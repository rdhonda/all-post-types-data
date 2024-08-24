jQuery(document).ready(function ($) {
    $('.toggle-content').on('click', function (e) {
        e.preventDefault();
        var postId = $(this).data('post-id');
        var blogId = $(this).data('blog-id');
        var contentRow = $('#content-' + postId);

        if (contentRow.is(':visible')) {
            contentRow.hide();
            return;
        }

        $.ajax({
            url: aptData.ajax_url,
            type: 'POST',
            data: {
                action: 'apt_get_post_details',
                post_id: postId,
                blog_id: blogId,
                nonce: aptData.nonce
            },
            success: function (response) {
                if (response.success) {
                    var content = '<h4>Content:</h4>' + response.data.content +
                        response.data.custom_fields +
                        response.data.taxonomies;
                    contentRow.find('td').html(content);
                    contentRow.show();
                } else {
                    alert('Error: ' + response.data);
                }
            },
            error: function () {
                alert('An error occurred while fetching the post details.');
            }
        });
    });

    $('#export-csv').on('click', function () {
        var postType = $(this).data('post-type');
        var blogId = $(this).data('blog-id');

        $.ajax({
            url: aptData.ajax_url,
            type: 'POST',
            data: {
                action: 'apt_export_csv',
                post_type: postType,
                blog_id: blogId,
                nonce: aptData.nonce
            },
            success: function (response) {
                if (response.success) {
                    var blob = new Blob([response.data.csv_content], { type: 'text/csv;charset=utf-8;' });
                    var link = document.createElement("a");
                    if (link.download !== undefined) {
                        var url = URL.createObjectURL(blob);
                        link.setAttribute("href", url);
                        link.setAttribute("download", postType + "_export.csv");
                        link.style.visibility = 'hidden';
                        document.body.appendChild(link);
                        link.click();
                        document.body.removeChild(link);
                    }
                } else {
                    alert('Error: ' + response.data);
                }
            },
            error: function () {
                alert('An error occurred while exporting the CSV.');
            }
        });
    });
});