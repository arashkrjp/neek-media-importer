( function( $, wp ) {
	'use strict';

	if ( ! wp || ! wp.media || ! wp.media.view || ! window.neekMediaImporterSettings ) {
		return;
	}

	var settings = window.neekMediaImporterSettings;
	var strings = settings.strings;
	var imageExtensions = [ 'jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp', 'avif', 'bmp', 'tif', 'tiff', 'heic', 'heif' ];
	var itemCounter = 0;

	function escapeHtml( value ) {
		return $( '<div>' ).text( value || '' ).html();
	}

	function extensionFromUrl( url ) {
		try {
			var path = new URL( url ).pathname;
			var filename = path.split( '/' ).pop() || '';
			var dotIndex = filename.lastIndexOf( '.' );
			return dotIndex > -1 ? filename.substring( dotIndex + 1 ).toLowerCase() : '';
		} catch ( error ) {
			return '';
		}
	}

	function isValidUrl( url ) {
		try {
			var parsed = new URL( url );
			return 'http:' === parsed.protocol || 'https:' === parsed.protocol;
		} catch ( error ) {
			return false;
		}
	}

	function isImageUrl( url ) {
		return imageExtensions.indexOf( extensionFromUrl( url ) ) !== -1;
	}

	function outputOptions( sourceExtension ) {
		var formats = [
			{ value: 'jpg', label: 'JPG' },
			{ value: 'webp', label: 'WEBP' },
			{ value: 'avif', label: 'AVIF' }
		];

		return formats.map( function( format ) {
			var sourceMatches = ( 'jpg' === format.value && [ 'jpg', 'jpeg', 'jpe' ].indexOf( sourceExtension ) !== -1 ) ||
				sourceExtension === format.value;
			var supported = settings.supportedTypes[ format.value ] !== false;
			var disabled = sourceMatches || ! supported;
			var suffix = ! supported ? ' — ' + strings.unsupported : '';

			return '<option value="' + format.value + '"' + ( disabled ? ' disabled' : '' ) + '>' +
				format.label + suffix + '</option>';
		} ).join( '' );
	}

	var NeekMediaImporterView = wp.Backbone.View.extend( {
		className: 'neek-media-importer-app',

		events: {
			'click .neek-media-importer-add': 'addUrls',
			'click .neek-media-importer-transfer-all': 'transferAll',
			'click .neek-media-importer-clear': 'clearCompleted',
			'click .neek-media-importer-remove': 'removeItem',
			'click .neek-media-importer-transfer': 'transferSingle',
			'input .neek-media-importer-url': 'handleUrlChange'
		},

		initialize: function() {
			this.items = [];
			this.activeTransfers = 0;
			this.concurrency = 2;
		},

		render: function() {
			this.$el.html(
				'<div class="neek-media-importer-shell">' +
					'<div class="neek-media-importer-header">' +
						'<h2>' + escapeHtml( strings.heading ) + '</h2>' +
						'<p>' + escapeHtml( strings.urlHelp ) + '</p>' +
					'</div>' +
					'<label class="screen-reader-text" for="neek-media-importer-urls">' + escapeHtml( strings.urlHelp ) + '</label>' +
					'<textarea id="neek-media-importer-urls" class="neek-media-importer-urls" rows="5" placeholder="https://example.com/image.jpg&#10;https://example.com/video.mp4"></textarea>' +
					'<div class="neek-media-importer-toolbar">' +
						'<button type="button" class="button button-primary neek-media-importer-add">' + escapeHtml( strings.addUrls ) + '</button>' +
						'<span class="neek-media-importer-notice" role="status" aria-live="polite"></span>' +
					'</div>' +
					'<div class="neek-media-importer-items" aria-live="polite"></div>' +
					'<div class="neek-media-importer-empty">' + escapeHtml( strings.noItems ) + '</div>' +
					'<div class="neek-media-importer-footer">' +
						'<button type="button" class="button button-primary neek-media-importer-transfer-all">' + escapeHtml( strings.transferAll ) + '</button>' +
						'<button type="button" class="button neek-media-importer-clear">' + escapeHtml( strings.clearCompleted ) + '</button>' +
						'<span class="neek-media-importer-summary"></span>' +
					'</div>' +
				'</div>'
			);

			this.updateControls();
			return this;
		},

		addUrls: function() {
			var view = this;
			var raw = this.$( '.neek-media-importer-urls' ).val();
			var candidates = raw.split( /\r?\n/ ).map( function( url ) {
				return url.trim();
			} ).filter( Boolean );
			var existing = this.items.map( function( item ) {
				return item.url;
			} );
			var invalid = false;
			var duplicate = false;
			var added = 0;

			candidates.forEach( function( url ) {
				if ( view.items.length >= settings.maxItems ) {
					return;
				}

				if ( ! isValidUrl( url ) ) {
					invalid = true;
					return;
				}

				if ( existing.indexOf( url ) !== -1 ) {
					duplicate = true;
					return;
				}

				view.createItem( url );
				existing.push( url );
				added++;
			} );

			if ( this.items.length >= settings.maxItems && candidates.length > added ) {
				this.showNotice( strings.limitReached, 'warning' );
			} else if ( invalid ) {
				this.showNotice( strings.invalidUrls, 'error' );
			} else if ( duplicate ) {
				this.showNotice( strings.duplicateUrls, 'warning' );
			} else {
				this.showNotice( '', '' );
			}

			if ( added ) {
				this.$( '.neek-media-importer-urls' ).val( '' );
			}

			this.updateControls();
		},

		createItem: function( url ) {
			var id = ++itemCounter;
			var image = isImageUrl( url );
			var extension = extensionFromUrl( url );
			var item = {
				id: id,
				url: url,
				status: 'pending',
				request: null
			};
			var imageFields = image ? (
				'<div class="neek-media-importer-image-options">' +
					'<label><span>' + escapeHtml( strings.format ) + '</span>' +
						'<select class="neek-media-importer-format">' +
							'<option value="original">' + escapeHtml( strings.noConversion ) + '</option>' +
							outputOptions( extension ) +
						'</select>' +
					'</label>' +
					'<label><span>' + escapeHtml( strings.quality ) + '</span>' +
						'<input class="neek-media-importer-quality" type="number" min="1" max="100" value="82">' +
					'</label>' +
					'<label><span>' + escapeHtml( strings.maxWidth ) + '</span>' +
						'<input class="neek-media-importer-width" type="number" min="1" max="12000" placeholder="' + escapeHtml( strings.optional ) + '">' +
					'</label>' +
					'<label><span>' + escapeHtml( strings.maxHeight ) + '</span>' +
						'<input class="neek-media-importer-height" type="number" min="1" max="12000" placeholder="' + escapeHtml( strings.optional ) + '">' +
					'</label>' +
				'</div>'
			) : '<p class="neek-media-importer-hint">' + escapeHtml( strings.imageHint ) + '</p>';

			this.items.push( item );
			this.$( '.neek-media-importer-items' ).append(
				'<article class="neek-media-importer-item" data-id="' + id + '">' +
					'<div class="neek-media-importer-item-number">#' + id + '</div>' +
					'<div class="neek-media-importer-preview" aria-hidden="true"><span class="dashicons dashicons-admin-media"></span></div>' +
					'<div class="neek-media-importer-fields">' +
						'<label class="neek-media-importer-url-field"><span>' + escapeHtml( strings.url ) + '</span>' +
							'<input class="neek-media-importer-url" type="url" value="' + escapeHtml( url ) + '">' +
						'</label>' +
						imageFields +
						'<div class="neek-media-importer-status" role="status">' + escapeHtml( strings.queued ) + '</div>' +
					'</div>' +
					'<div class="neek-media-importer-actions">' +
						'<button type="button" class="button button-primary neek-media-importer-transfer">' + escapeHtml( strings.transfer ) + '</button>' +
						'<button type="button" class="button-link neek-media-importer-remove" aria-label="' + escapeHtml( strings.remove ) + '">' +
							'<span class="dashicons dashicons-no-alt"></span>' +
						'</button>' +
					'</div>' +
				'</article>'
			);
		},

		handleUrlChange: function( event ) {
			var $item = $( event.currentTarget ).closest( '.neek-media-importer-item' );
			var item = this.getItem( $item.data( 'id' ) );
			if ( item && 'pending' === item.status ) {
				item.url = $( event.currentTarget ).val().trim();
			}
		},

		removeItem: function( event ) {
			var $item = $( event.currentTarget ).closest( '.neek-media-importer-item' );
			var item = this.getItem( $item.data( 'id' ) );

			if ( ! item || 'transferring' === item.status ) {
				return;
			}

			this.items = this.items.filter( function( candidate ) {
				return candidate.id !== item.id;
			} );
			$item.remove();
			this.updateControls();
		},

		transferSingle: function( event ) {
			var $item = $( event.currentTarget ).closest( '.neek-media-importer-item' );
			var item = this.getItem( $item.data( 'id' ) );
			if ( item && ( 'pending' === item.status || 'failed' === item.status ) ) {
				item.status = 'queued';
				this.processQueue();
			}
		},

		transferAll: function() {
			var queued = 0;
			this.items.forEach( function( item ) {
				if ( 'pending' === item.status || 'failed' === item.status ) {
					item.status = 'queued';
					queued++;
				}
			} );

			if ( ! queued ) {
				this.showNotice( strings.nothingToTransfer, 'warning' );
				return;
			}

			this.showNotice( '', '' );
			this.processQueue();
		},

		processQueue: function() {
			var next;

			while ( this.activeTransfers < this.concurrency ) {
				next = this.items.find( function( item ) {
					return 'queued' === item.status;
				} );

				if ( ! next ) {
					break;
				}

				this.startTransfer( next );
			}

			this.updateControls();
		},

		startTransfer: function( item ) {
			var view = this;
			var $item = this.getItemElement( item.id );
			var url = $item.find( '.neek-media-importer-url' ).val().trim();

			if ( ! isValidUrl( url ) ) {
				item.status = 'failed';
				this.setItemState( item, 'failed', strings.invalidUrls );
				return;
			}

			item.url = url;
			item.status = 'transferring';
			this.activeTransfers++;
			this.setItemState( item, 'transferring', strings.transferring );

			item.request = $.ajax( {
				url: window.ajaxurl,
				method: 'POST',
				dataType: 'json',
				data: {
					action: settings.action,
					nonce: settings.nonce,
					url: item.url,
					format: $item.find( '.neek-media-importer-format' ).val() || 'original',
					quality: $item.find( '.neek-media-importer-quality' ).val() || 82,
					max_width: $item.find( '.neek-media-importer-width' ).val() || 0,
					max_height: $item.find( '.neek-media-importer-height' ).val() || 0
				}
			} ).done( function( response ) {
				if ( response && response.success ) {
					item.status = 'complete';
					item.attachment = response.data.attachment;
					view.setItemState( item, 'complete', strings.complete );
					view.showPreview( item, response.data );
					view.registerAttachment( response.data.attachment );
				} else {
					item.status = 'failed';
					view.setItemState( item, 'failed', view.responseMessage( response ) );
				}
			} ).fail( function( xhr ) {
				item.status = 'failed';
				view.setItemState( item, 'failed', view.xhrMessage( xhr ) );
			} ).always( function() {
				item.request = null;
				view.activeTransfers = Math.max( 0, view.activeTransfers - 1 );
				view.updateControls();
				window.setTimeout( function() {
					view.processQueue();
				}, settings.requestDelay );
			} );
		},

		setItemState: function( item, state, message ) {
			var $item = this.getItemElement( item.id );
			$item.removeClass( 'is-pending is-queued is-transferring is-complete is-failed' ).addClass( 'is-' + state );
			$item.find( 'input, select, .neek-media-importer-transfer, .neek-media-importer-remove' ).prop( 'disabled', 'transferring' === state || 'complete' === state );
			$item.find( '.neek-media-importer-status' ).html(
				( 'transferring' === state ? '<span class="spinner is-active"></span>' : '' ) + escapeHtml( message )
			);
			$item.find( '.neek-media-importer-transfer' ).text( 'failed' === state ? 'Retry' : strings.transfer );
		},

		showPreview: function( item, data ) {
			var $preview = this.getItemElement( item.id ).find( '.neek-media-importer-preview' );
			$preview.html( '<img src="' + escapeHtml( data.thumbnail ) + '" alt="">' );
		},

		registerAttachment: function( attachmentData ) {
			if ( ! attachmentData || ! attachmentData.id ) {
				return;
			}

			var attachment = wp.media.model.Attachment.get( attachmentData.id );
			attachment.set( attachmentData );

			if ( wp.media.model.Attachments.all && ! wp.media.model.Attachments.all.get( attachmentData.id ) ) {
				wp.media.model.Attachments.all.add( attachment );
			}
		},

		clearCompleted: function() {
			var completedIds = this.items.filter( function( item ) {
				return 'complete' === item.status;
			} ).map( function( item ) {
				return item.id;
			} );

			this.items = this.items.filter( function( item ) {
				return 'complete' !== item.status;
			} );

			completedIds.forEach( function( id ) {
				this.getItemElement( id ).remove();
			}, this );

			this.updateControls();
		},

		getItem: function( id ) {
			id = parseInt( id, 10 );
			return this.items.find( function( item ) {
				return item.id === id;
			} );
		},

		getItemElement: function( id ) {
			return this.$( '.neek-media-importer-item[data-id="' + id + '"]' );
		},

		responseMessage: function( response ) {
			return response && response.data && response.data.message ? response.data.message : strings.unknownError;
		},

		xhrMessage: function( xhr ) {
			return xhr && xhr.responseJSON ? this.responseMessage( xhr.responseJSON ) : strings.unknownError;
		},

		showNotice: function( message, type ) {
			this.$( '.neek-media-importer-notice' )
				.attr( 'class', 'neek-media-importer-notice' + ( type ? ' is-' + type : '' ) )
				.text( message );
		},

		updateControls: function() {
			var pending = this.items.filter( function( item ) {
				return 'pending' === item.status || 'failed' === item.status;
			} ).length;
			var completed = this.items.filter( function( item ) {
				return 'complete' === item.status;
			} ).length;

			this.$( '.neek-media-importer-empty' ).toggle( 0 === this.items.length );
			this.$( '.neek-media-importer-footer' ).toggle( this.items.length > 0 );
			this.$( '.neek-media-importer-transfer-all' ).prop( 'disabled', ! pending );
			this.$( '.neek-media-importer-clear' ).prop( 'disabled', ! completed );
			this.$( '.neek-media-importer-summary' ).text(
				this.items.length ? completed + ' / ' + this.items.length + ' complete' : ''
			);
		}
	} );

	function patchMediaFrame( FrameClass ) {
		if ( ! FrameClass || FrameClass.prototype.neekMediaImporterEnabled ) {
			return;
		}

		var originalBindHandlers = FrameClass.prototype.bindHandlers;

		FrameClass.prototype.neekMediaImporterEnabled = true;
		FrameClass.prototype.bindHandlers = function() {
			originalBindHandlers.apply( this, arguments );
			this.on( 'router:create:browse', this.neekMediaImporterRouter, this );
			this.on( 'content:render:neek-media-importer', this.neekMediaImporterContent, this );
		};

		FrameClass.prototype.neekMediaImporterRouter = function( routerView ) {
			routerView.set( {
				'neek-media-importer': {
					text: strings.tabTitle,
					priority: 70
				}
			} );
		};

		FrameClass.prototype.neekMediaImporterContent = function() {
			this.content.set( new NeekMediaImporterView( { controller: this } ) );
		};
	}

	patchMediaFrame( wp.media.view.MediaFrame.Select );

	if ( wp.media.view.MediaFrame.Manage &&
		wp.media.view.MediaFrame.Manage.prototype.bindHandlers !== wp.media.view.MediaFrame.Select.prototype.bindHandlers ) {
		patchMediaFrame( wp.media.view.MediaFrame.Manage );
	}

	$( function() {
		var $adminApp = $( '#neek-media-importer-admin-app' );
		if ( $adminApp.length ) {
			$adminApp.append( new NeekMediaImporterView().render().el );
		}
	} );
} )( jQuery, window.wp );
