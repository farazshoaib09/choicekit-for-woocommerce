( function( $ ) {
	'use strict';

	function formatPrice( amount ) {
		var data = window.ChoiceKitData && window.ChoiceKitData.currency ? window.ChoiceKitData.currency : {};
		var decimals = parseInt( data.format_num_decimals, 10 );
		var decimalSep = data.format_decimal_sep || '.';
		var thousandSep = data.format_thousand_sep || ',';
		var symbol = data.format_symbol || '$';
		var format = data.format || '%1$s%2$s';
		var fixed = ( Math.max( 0, amount ) ).toFixed( isNaN( decimals ) ? 2 : decimals );
		var parts = fixed.split( '.' );

		parts[0] = parts[0].replace( /\B(?=(\d{3})+(?!\d))/g, thousandSep );

		var number = parts.join( decimalSep );
		return format.replace( '%1$s', symbol ).replace( '%2$s', number );
	}

	function displayPrice( amount ) {
		var decoder = document.createElement( 'textarea' );
		decoder.innerHTML = formatPrice( amount );
		return decoder.value;
	}

	function updateBuilder( $builder ) {
		var payload = { groups: [] };
		var basePrice = parseFloat( $builder.data( 'base-price' ) ) || 0;
		var componentTotal = 0;
		var components = [];
		var selectedCount = 0;
		var valid = true;

		$builder.find( '.choicekit-group' ).each( function() {
			var $group = $( this );
			var groupId = String( $group.data( 'group-id' ) || '' );
			var min = parseInt( $group.data( 'min' ), 10 ) || 0;
			var max = parseInt( $group.data( 'max' ), 10 ) || 0;
			var groupDiscountType = String( $group.data( 'discount-type' ) || 'none' );
			var groupDiscountAmount = parseFloat( $group.data( 'discount-amount' ) ) || 0;
			var groupDiscountMinItems = parseInt( $group.data( 'discount-min-items' ), 10 ) || Math.max( 1, min );
			var groupTotal = 0;
			var groupDiscount = 0;
			var selected = [];
			var groupComponents = [];

			$group.find( 'input:checked' ).each( function() {
				var $option = $( this ).closest( '.choicekit-option' );
				selected.push( parseInt( $( this ).val(), 10 ) );
				var price = parseFloat( $option.data( 'price' ) ) || 0;
				groupTotal += price;
				groupComponents.push( {
					$option: $option,
					originalPrice: price,
					price: price,
					ratio: parseFloat( $option.data( 'display-ratio' ) ) || 1
				} );
			} );

			if ( selected.length >= groupDiscountMinItems && 'percent' === groupDiscountType ) {
				groupDiscount = groupTotal * Math.min( 100, groupDiscountAmount ) / 100;
			} else if ( selected.length >= groupDiscountMinItems && 'fixed' === groupDiscountType ) {
				groupDiscount = groupDiscountAmount;
			}

			groupDiscount = Math.min( groupTotal, Math.max( 0, groupDiscount ) );
			var groupFactor = groupTotal > 0 ? ( groupTotal - groupDiscount ) / groupTotal : 1;
			groupComponents.forEach( function( component ) {
				component.price *= groupFactor;
				components.push( component );
			} );
			selectedCount += selected.length;
			componentTotal += groupTotal - groupDiscount;

			$group.find( '.choicekit-count' ).text( max > 0 ? selected.length + ' / ' + max : selected.length + ' ' + window.ChoiceKitData.i18n.selected );

			if ( selected.length < min || ( max > 0 && selected.length > max ) ) {
				valid = false;
			}

			payload.groups.push( {
				id: groupId,
				selected: selected
			} );
		} );

		var discountType = String( $builder.data( 'discount-type' ) || 'none' );
		var discountAmount = parseFloat( $builder.data( 'discount-amount' ) ) || 0;
		var discount = 0;

		if ( valid && selectedCount > 0 && 'percent' === discountType ) {
			discount = componentTotal * Math.min( 100, discountAmount ) / 100;
		} else if ( valid && selectedCount > 0 && 'fixed' === discountType ) {
			discount = discountAmount;
		}

		discount = Math.min( componentTotal, Math.max( 0, discount ) );

		var kitFactor = componentTotal > 0 ? ( componentTotal - discount ) / componentTotal : 1;
		var originalTotal = basePrice;
		var displayedTotal = components.reduce( function( total, component ) {
			var originalDisplay = displayPrice( component.originalPrice * component.ratio );
			var discountedDisplay = displayPrice( component.price * kitFactor * component.ratio );
			var $price = component.$option.find( '.choicekit-option-price' );
			originalTotal += component.originalPrice * component.ratio;
			if ( originalDisplay !== discountedDisplay ) {
				$price.empty().append( $( '<del/>' ).text( originalDisplay ), $( '<ins/>' ).text( discountedDisplay ) );
			} else {
				$price.text( discountedDisplay );
			}
			return total + component.price * kitFactor * component.ratio;
		}, basePrice );
		$builder.find( '.choicekit-option' ).not( '.choicekit-option-selected' ).each( function() {
			var $option = $( this );
			var price = parseFloat( $option.data( 'price' ) ) || 0;
			var ratio = parseFloat( $option.data( 'display-ratio' ) ) || 1;
			$option.find( '.choicekit-option-price' ).text( displayPrice( price * ratio ) );
		} );
		var originalDisplayTotal = displayPrice( originalTotal );
		var discountedDisplayTotal = displayPrice( displayedTotal );
		var $total = $builder.find( '.choicekit-total' );
		if ( originalDisplayTotal !== discountedDisplayTotal ) {
			$total.empty().append( $( '<del/>' ).text( originalDisplayTotal ), $( '<ins/>' ).text( discountedDisplayTotal ) );
		} else {
			$total.text( discountedDisplayTotal );
		}
		$builder.find( '.choicekit-payload' ).val( JSON.stringify( payload ) );
		$builder.toggleClass( 'choicekit-is-valid', valid && selectedCount > 0 );
	}

	$( function() {
		$( '.choicekit-builder' ).each( function() {
			var $builder = $( this );
			$builder.find( '.choicekit-option input:checked' ).closest( '.choicekit-option' ).addClass( 'choicekit-option-selected' );
			updateBuilder( $builder );
		} );

		$( document ).on( 'change', '.choicekit-builder input', function() {
			var $builder = $( this ).closest( '.choicekit-builder' );
			var $group = $( this ).closest( '.choicekit-group' );
			var max = parseInt( $group.data( 'max' ), 10 ) || 0;
			var $checked = $group.find( 'input:checked' );

			if ( 'checkbox' === $( this ).attr( 'type' ) && max > 0 && $checked.length > max ) {
				this.checked = false;
			}

			$builder.find( '.choicekit-option' ).removeClass( 'choicekit-option-selected' );
			$builder.find( '.choicekit-option input:checked' ).closest( '.choicekit-option' ).addClass( 'choicekit-option-selected' );
			$builder.find( '.choicekit-error' ).text( '' );
			updateBuilder( $builder );
		} );

		$( document ).on( 'submit', 'form.cart', function( event ) {
			var $builder = $( this ).find( '.choicekit-builder' );

			if ( ! $builder.length ) {
				return;
			}

			updateBuilder( $builder );

			if ( ! $builder.hasClass( 'choicekit-is-valid' ) ) {
				event.preventDefault();
				$builder.find( '.choicekit-error' ).text( window.ChoiceKitData.i18n.choose_required );
			}
		} );
	} );
}( jQuery ) );
