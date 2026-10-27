( function( $ ) {
	'use strict';

	function initProductSearch( $context ) {
		$( document.body ).trigger( 'wc-enhanced-select-init' );
	}

	function updateEmptyState( $admin ) {
		$admin.toggleClass( 'choicekit-no-groups', 0 === $admin.find( '.choicekit-groups .choicekit-group' ).length );
	}

	$( function() {
		var $admin = $( '.choicekit-admin' );

		if ( ! $admin.length ) {
			return;
		}

		initProductSearch( $admin );
		updateEmptyState( $admin );

		$admin.on( 'click', '.choicekit-add-group', function() {
			var index = parseInt( $admin.attr( 'data-next-index' ), 10 ) || 0;
			var template = wp.template( 'choicekit-group-row' );
			var html = template( { index: index } );

			$admin.find( '.choicekit-groups' ).append( html );
			$admin.attr( 'data-next-index', index + 1 );
			initProductSearch( $admin );
			updateEmptyState( $admin );
		} );

		$admin.on( 'click', '.choicekit-remove-group', function() {
			$( this ).closest( '.choicekit-group' ).remove();
			updateEmptyState( $admin );
		} );

		$admin.on( 'input', '.choicekit-group-title-input', function() {
			var title = $( this ).val() || 'New group';
			$( this ).closest( '.choicekit-group' ).find( '.choicekit-card-header h3' ).text( title );
		} );
	} );
}( jQuery ) );
