jQuery(function($) {
	var options = $('#iposts-publish-options').hide();
	$("[name=iposts\\[app_id\\]]").keyup(function() {
		this.value = this.value.replace(/https?:\/\/.*.apple.com\/.*id([0-9]+).*/, "$1");
		this.value = this.value.replace(/[^0-9]/g, '');
		if (this.value.length) {
			options.slideDown();
		} else {
			options.slideUp();
		}
	});

	$('.iposts-tip').click(function() {
		var title = $(this).attr('title');
		if (title) {
			alert(title);
			return false;
		}
	});
});