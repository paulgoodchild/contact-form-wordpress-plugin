( function( $ ) {
	$( document ).ready( function() {
		$( '.cntctfrm_contact_form' ).each( function() {
			var form = $( this );
			var formSubmit = $( this ).find( '.cntctfrm_contact_submit' );
			var formNumber = $( this ).find( 'input[name="cntctfrm_form_submited"]' ).val();
			const root = document.getElementById( 'cntctfrm_esign_signature_' + formNumber );
			const resetCanvas = document.getElementById( 'cntctfrm_esign_reset_' + formNumber );
			var wrapWidth = $( root ).parents( '.cntctfrm_column' ).find( '.cntctfrm_field_subject_wrap input' ).outerWidth();
			$( resetCanvas ).css({ left: wrapWidth - 25 });

			/* Call signature with the root element and the options object, saving its reference in a variable */
			const component = Signature( root, {
				width: wrapWidth,
				height: 150,
				instructions: cntctfrm_vars.cntctfrm_sign_text
			});

			resetCanvas.addEventListener( 'click', () => {
				component.value = [];
				document.getElementById( 'cntctfrm_esign_start_' + formNumber ).value = 0;
			});

			$( root ).on( 'click touchstart', function() {
				document.getElementById( 'cntctfrm_esign_start_' + formNumber ).value = 1;
			} );

			window.addEventListener( 'resize', () => {
				wrapWidth = $( root ).parents( '.cntctfrm_column' ).find( '.cntctfrm_field_subject_wrap input' ).outerWidth();
				component.width = wrapWidth;
				component.value = [];
				$( resetCanvas ).css({ left: wrapWidth - 25 });

			});

			formSubmit.click( function() {
				document.getElementById( 'cntctfrm_esign_image_' + formNumber ).value = component.getImage();
				setTimeout( continueExecution, 100, form );
				return false;
			});
		});
	});
	function continueExecution( form ){
		form.submit();
	}
} )(jQuery);
