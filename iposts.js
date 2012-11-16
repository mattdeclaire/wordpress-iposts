jQuery(function($) {
	var options = $('#iposts-publish-options').hide();
	$("[name=iposts\\[app_id\\]]").change(function() {
		this.value = this.value.replace(/[^0-9]/g, '');
		options.slideToggle(this.value.length);
	});

	$('.iposts-tip').click(function() {
		var title = $(this).attr('title');
		if (title) {
			alert(title);
			return false;
		}
	});
});