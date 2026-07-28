/**
 * Editor integration for the Freshsales form action.
 *
 * Registers a custom mapping control that lists each FORM field on the left with a
 * Freshsales-field dropdown on the right (the inverse of Elementor's default mapping),
 * and rebuilds its rows from the form's fields when the Freshsales section is opened.
 *
 * The Freshsales field list comes from PHP (window.CornerstoneFreshsalesData). No libraries.
 *
 * @package Cornerstone\Elementor_Freshsales
 */
( function ( $ ) {
	'use strict';

	// Form field types that carry no mappable value.
	var SKIP_TYPES = [ 'step', 'html' ];

	function remoteFields() {
		return ( window.CornerstoneFreshsalesData && window.CornerstoneFreshsalesData.remoteFields ) || [];
	}

	/**
	 * The control view: rows are the form's fields; each row's dropdown selects a
	 * Freshsales field. Mirrors Elementor Pro's Fields_Map view, inverted.
	 */
	function defineControlView() {
		return elementor.modules.controls.Repeater.extend( {
			onBeforeRender: function () {
				this.$el.hide();
			},

			// Build one row per form field, preserving any saved Freshsales selection.
			rebuild: function () {
				var self = this,
					saved = {};

				self.collection.each( function ( model ) {
					if ( model.get( 'local_id' ) ) {
						saved[ model.get( 'local_id' ) ] = model.get( 'remote_id' );
					}
				} );

				self.collection.reset();

				self.elementSettingsModel.get( 'form_fields' ).each( function ( fieldModel ) {
					if ( -1 !== SKIP_TYPES.indexOf( fieldModel.get( 'field_type' ) ) ) {
						return;
					}

					var localId = fieldModel.get( 'custom_id' );

					if ( ! localId ) {
						return;
					}

					self.collection.add( {
						local_id: localId,
						remote_id: saved[ localId ] ? saved[ localId ] : ''
					} );
				} );

				self.render();
			},

			onRender: function () {
				elementor.modules.controls.Base.prototype.onRender.apply( this, arguments );

				var self = this,
					fields = remoteFields(),
					formFields = self.elementSettingsModel.get( 'form_fields' );

				// Build grouped options: "None" first, then one <optgroup> per section.
				// Group keys are non-numeric ('g0', 'g1', ...) so the select template keeps
				// insertion order (numeric keys would sort ahead of the "None" entry).
				var order = [],
					byGroup = {};

				fields.forEach( function ( field ) {
					var group = field.group || 'Fields';

					if ( ! byGroup[ group ] ) {
						byGroup[ group ] = {};
						order.push( group );
					}

					byGroup[ group ][ field.remote_id ] = field.remote_label + ( field.remote_required ? ' *' : '' );
				} );

				var groups = { '': '- ' + 'None' + ' -' };

				order.forEach( function ( group, index ) {
					groups[ 'g' + index ] = { label: group, options: byGroup[ group ] };
				} );

				self.children.each( function ( rowView ) {
					var remoteControl = rowView.children.last(), // the remote_id SELECT
						localId = rowView.model.get( 'local_id' ),
						formField = formFields.findWhere( { custom_id: localId } ),
						label = formField ? ( formField.get( 'field_label' ) || localId ) : localId;

					remoteControl.model.set( 'label', label );
					remoteControl.model.set( 'groups', groups );
					remoteControl.render();

					rowView.$el.find( '.elementor-repeater-row-tools' ).hide();
					rowView.$el.find( '.elementor-repeater-row-controls' )
						.removeClass( 'elementor-repeater-row-controls' )
						.find( '.elementor-control' ).css( { paddingBottom: 0 } );
				} );

				self.$el.find( '.elementor-button-wrapper' ).remove();

				if ( self.children.length ) {
					self.$el.show();
				}
			}
		} );
	}

	/**
	 * Rebuilds the mapping rows whenever the Freshsales section is opened.
	 */
	function defineModule() {
		return elementorModules.editor.utils.Module.extend( {
			elementType: null,

			__construct: function ( elementType ) {
				this.elementType = elementType;
			},

			onInit: function () {
				elementor.channels.editor.on( 'section:activated', this.onSectionActivated.bind( this ) );
			},

			onSectionActivated: function ( sectionName, editor ) {
				if ( 'section_freshsales' !== sectionName ) {
					return;
				}

				var model = editor.getOption( 'editedElementView' ).getEditModel();
				var type = model.get( 'elType' );

				if ( 'widget' === type ) {
					type = model.get( 'widgetType' );
				}

				if ( this.elementType !== type ) {
					return;
				}

				var self = this;

				setTimeout( function () {
					var view = self.getEditorControlView( 'freshsales_fields_map' );

					if ( view && 'function' === typeof view.rebuild ) {
						view.rebuild();
					}
				}, 10 );
			}
		} );
	}

	function boot() {
		if ( ! window.elementor || ! elementor.modules || ! elementor.modules.controls ||
			'undefined' === typeof elementorModules || ! elementorModules.editor || ! elementorModules.editor.utils ) {
			return;
		}

		elementor.addControlView( 'cornerstone_freshsales_map', defineControlView() );

		var FreshsalesModule = defineModule();
		new FreshsalesModule( 'form' );
	}

	if ( window.elementor && elementor.modules && elementor.channels && elementor.channels.editor ) {
		boot();
	} else {
		$( window ).on( 'elementor:init', boot );
	}
}( jQuery ) );
