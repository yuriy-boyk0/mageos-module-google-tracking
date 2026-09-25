define(["jquery"], function ($) {

    return function (config) {
        let xhrUrl = config.xhrUrl,
            downloadUrl = config.downloadUrl,
            exportButton = $('#googletagmanager_export_export_button'),
            measurementGa4Input = $('#googletagmanager_general_ga4_measurement_id');

        exportButton.click(function () {
            let ga4MeasurementId = measurementGa4Input.val(),
                errorMessageContainer = $('.message-error');

            $.ajax({
                url: xhrUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    ga4MeasurementId: ga4MeasurementId
                },
                success: function (data) {
                    if (data.status) {
                        errorMessageContainer.hide();
                        window.location = downloadUrl;
                    } else {
                        errorMessageContainer.show();
                        errorMessageContainer.html(data.message);
                    }
                }
            });
        });
    }
});
