jQuery(document).ready(function($) {

    // --- 1. Toggle Auto vs Manual Rotation UI ---
    function toggleRotationUI() {
        var isAuto = $('#fsbhoa_dk_enable_rotation').is(':checked');

        // Toggle Manual vs Auto sections
        $('#fsbhoa-manual-pin-wrap').closest('tr').toggle(!isAuto);
        $('#fsbhoa-auto-status-wrap').closest('tr').toggle(isAuto);

        // Toggle Grace Period rows (only relevant when Auto is ON)
        $('#fsbhoa_dk_grace_before').closest('tr').toggle(isAuto);
        $('#fsbhoa_dk_grace_after').closest('tr').toggle(isAuto);
    }

    // Run on initial page load and when the checkbox changes
    toggleRotationUI();
    $('#fsbhoa_dk_enable_rotation').on('change', toggleRotationUI);


    // --- 2. Save Settings Handler ---
    $('#fsbhoa-save-doorking-settings-button').on('click', function(e) {
        e.preventDefault();
        var btn = $(this);
        var feedback = $('#fsbhoa-save-feedback');

        // Gather all inputs on the settings page
        var optionsData = [];
        $('#fsbhoa-doorking-settings-page input').each(function() {
            var $el = $(this);
            var name = $el.attr('name');
            if (!name) return;

            if ($el.attr('type') === 'checkbox') {
                optionsData.push({
                    name: name,
                    value: $el.is(':checked') ? '1' : '0'
                });
            } else {
                optionsData.push({
                    name: name,
                    value: $el.val()
                });
            }
        });

        btn.prop('disabled', true);
        feedback.text('Saving...').css('color', '#000').show();

        $.post(fsbhoa_dk_vars.ajax_url, {
            action: 'fsbhoa_save_doorking_settings',
            nonce: fsbhoa_dk_vars.nonce,
            options: optionsData
        }, function(response) {
            btn.prop('disabled', false);
            if (response.success) {
                feedback.text(response.data).css('color', 'green');
            } else {
                feedback.text('Error: ' + response.data).css('color', 'red');
            }
            setTimeout(function() { feedback.fadeOut(); }, 3000);
        }).fail(function() {
            btn.prop('disabled', false);
            feedback.text('AJAX request failed.').css('color', 'red');
            setTimeout(function() { feedback.fadeOut(); }, 3000);
        });
    });


    // --- 3. Manual RAM Export Trigger ---
    $('#fsbhoa-export-ram-csv-btn').on('click', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var $feedback = $('#fsbhoa-export-feedback');

        $btn.prop('disabled', true).text('Generating...');
        $feedback.show().css('color', '#666').text('Writing CSV and trigger files...');

        $.post(fsbhoa_dk_vars.ajax_url, {
            action: 'fsbhoa_dk_generate_csv',
            nonce: fsbhoa_dk_vars.nonce
        })
        .done(function(response) {
            if (response.success) {
                $feedback.css('color', '#00a32a').text('✓ ' + response.data);
            } else {
                $feedback.css('color', '#d63638').text('✗ ' + (response.data || 'Export failed.'));
            }
        })
        .fail(function() {
            $feedback.css('color', '#d63638').text('✗ Server or network error.');
        })
        .always(function() {
            $btn.prop('disabled', false).text('Generate RAM CSV Now');
            setTimeout(function() {
                $feedback.fadeOut();
            }, 5000);
        });
    });

});

