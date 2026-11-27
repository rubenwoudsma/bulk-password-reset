/* Optional Media Library picker, loaded only on the authorized email screen. */
( function () {
	'use strict';
	const choose = document.getElementById( 'bpr-email-choose-logo' );
	if ( ! choose || ! window.wp || ! window.wp.media ) {
		return;
	}
	const input = document.getElementById( 'bpr-email-logo-id' );
	const option = document.getElementById( 'bpr-email-logo' );
	const preview = document.getElementById( 'bpr-email-logo-preview' );
	const clear = document.getElementById( 'bpr-email-clear-logo' );
	const frame = window.wp.media( {
		title: bprEmailSettings.title,
		button: { text: bprEmailSettings.button },
		library: { type: [ 'image/png', 'image/jpeg', 'image/gif' ] },
		multiple: false
	} );
	frame.on( 'select', function () {
		const attachment = frame.state().get( 'selection' ).first().toJSON();
		input.value = attachment.id;
		option.value = 'custom';
		const image = document.createElement( 'img' );
		image.src = attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url;
		image.alt = bprEmailSettings.alt;
		preview.replaceChildren( image );
	} );
	choose.addEventListener( 'click', function () { frame.open(); } );
	if ( clear ) {
		clear.addEventListener( 'click', function () {
			input.value = '0';
			option.value = 'none';
			preview.replaceChildren();
		} );
	}
}() );
