/**
 * JavaScript for RealLastUpdate special page
 */
( function () {
	'use strict';

	// Function to toggle between showing/hiding identical timestamps
	function toggleIdenticalDates( showAll ) {
		if ( showAll ) {
			// Show original timestamps, hide the "identical" text
			$( '.reallastupdate-identical-text' ).hide();
			$( '.reallastupdate-identical-date' ).show();
		} else {
			// Show "identical" text, hide the timestamps
			$( '.reallastupdate-identical-text' ).show();
			$( '.reallastupdate-identical-date' ).hide();
		}
	}

	// Function to save the preference
	function saveTogglePreference( showAll ) {
		toggleIdenticalDates( showAll );
		// Save the state in mw.user.options for future access
		mw.user.options.set( 'reallastupdate-showalldates', showAll ? '1' : '0' );

		return new mw.Api().saveOption( 'reallastupdate-showalldates', showAll ? '1' : '0' )
			.catch( function( error ) {
				mw.log.warn( 'Failed to save preference:', error );
				return false;
			} );

	}

	$( function () {
		// Initial state from user preference - first try to read from mw.user.options
		var showAllDates = mw.user.options.get( 'reallastupdate-showalldates' ) === '1';

		// Fall back to config if needed
		if ( mw.user.options.get( 'reallastupdate-showalldates' ) === null &&
		     mw.config.exists( 'wgRealLastUpdateShowAllDates' ) ) {
			showAllDates = mw.config.get( 'wgRealLastUpdateShowAllDates' );
		}

		// Create toggle widget
		var toggleSwitch = new OO.ui.ToggleSwitchWidget( {
			value: showAllDates
		} );

		toggleSwitch.onChange = function ( showAll ) {
			toggleIdenticalDates( showAll );
			saveTogglePreference( showAll );
		}

		var fieldLayout = new OO.ui.FieldLayout( toggleSwitch, {
			label: mw.msg( 'reallastupdate-show-all-dates' ),
			align: 'inline'
		} )

		var $container = $( '.reallastupdate-toggle-container' );
		$container.append( fieldLayout.$element );

		// Apply initial state
		toggleIdenticalDates( showAllDates );

		// Handle toggle change
		toggleSwitch.on( 'change', toggleSwitch.onChange );
	} );

}() );
