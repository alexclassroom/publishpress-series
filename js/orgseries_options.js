jQuery(document).ready(function($) {
	//Thickbox
    $(document).on('click','input[name="option_reset"]', function(){
        tb_show('Are you sure you want to Reset?','TB_inline?height=155&amp;width=300&amp;inlineId=TBcontent');
        return false;
    });

    $('input#TBcancel').click(function(){
        tb_remove();
    });

    $('input#TBsubmit').click(function(){
		$('input.reset_option', '#series_options' ).val('1');
        document.series_options.submit();
    });

	$('#custom_css').click(function(e) {
		var is_checked = $('input#custom_css').is(':checked');

		if (is_checked) {
			$("input[class='css_style']").attr('disabled', false);
		}

		if (!is_checked) {
			$("input[class='css_style']").attr('disabled', true);
		}
	});

    // Toggle a columns setting based on layout value
    $('#series_overview_page_layout').change(function(){
        if($(this).val() == 'grid'){
            $('.ppseries-settings-table').find('tr.pps-row-columns').show();
        } else {
            $('.ppseries-settings-table').find('tr.pps-row-columns').hide();
        }
	});
});
