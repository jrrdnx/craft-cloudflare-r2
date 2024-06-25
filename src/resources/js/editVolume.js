$(document).ready(function () {
  const $r2AccountIdInput = $('.r2-account-id');
  const $r2AccessKeyIdInput = $('.r2-key-id');
  const $r2SecretAccessKeyInput = $('.r2-secret-key');
  const $r2BucketSelect = $('.r2-bucket-select > select');
  const $r2RefreshBucketsBtn = $('.r2-refresh-buckets');
  const $r2RefreshBucketsSpinner = $r2RefreshBucketsBtn.parent().next().children();
  const $manualBucket = $('.r2-manualBucket');
  const $fsUrl = $('.fs-url');
  const $hasUrls = $('input[name=hasUrls]');
  let refreshingR2Buckets = false;

  $r2RefreshBucketsBtn.click(function() {
    if ($r2RefreshBucketsBtn.hasClass('disabled')) {
      return;
    }

    $r2RefreshBucketsBtn.addClass('disabled');
    $r2RefreshBucketsSpinner.removeClass('hidden');

    const data = {
	  accountId: $r2AccountIdInput.val(),
      keyId: $r2AccessKeyIdInput.val(),
      secret: $r2SecretAccessKeyInput.val()
    };

    Craft.sendActionRequest('POST', 'cloudflare-r2/buckets/load-bucket-data', {data})
      .then(({data}) => {
        if (!data.buckets.length) {
          return;
        }
        //
        const currentBucket = $r2BucketSelect.val();
        let currentBucketStillExists = false;

        refreshingR2Buckets = true;

        $r2BucketSelect.prop('readonly', false).empty();

        for (let i = 0; i < data.buckets.length; i++) {
          if (data.buckets[i].bucket == currentBucket) {
            currentBucketStillExists = true;
          }

          $r2BucketSelect.append('<option value="' + data.buckets[i].bucket + '" data-url-prefix="' + data.buckets[i].urlPrefix + '">' + data.buckets[i].bucket + '</option>');
        }

        if (currentBucketStillExists) {
          $r2BucketSelect.val(currentBucket);
        }

        refreshingR2Buckets = false;

        if (!currentBucketStillExists) {
          $r2BucketSelect.trigger('change');
        }
      })
      .catch(({response}) => {
        alert(response.data.message);
      })
      .finally(() => {
        $r2RefreshBucketsBtn.removeClass('disabled');
        $r2RefreshBucketsSpinner.addClass('hidden');
      });
  });

  $r2BucketSelect.change(function() {
    if (refreshingR2Buckets) {
      return;
    }

    const $selectedOption = $r2BucketSelect.children('option:selected');

    $fsUrl.val($selectedOption.data('url-prefix'));
  });

  const r2ChangeExpiryValue = function() {
    const parent = $(this).parents('.field');
    const amount = parent.find('.r2-expires-amount').val();
    const period = parent.find('.r2-expires-period select').val();

    const combinedValue = (parseInt(amount, 10) === 0 || period.length === 0) ? '' : amount + ' ' + period;

    parent.find('[type=hidden]').val(combinedValue);
  };

  $('.r2-expires-amount').keyup(r2ChangeExpiryValue).change(r2ChangeExpiryValue);
  $('.r2-expires-period select').change(r2ChangeExpiryValue);
});
