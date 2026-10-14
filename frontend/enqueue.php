<?php
/**
 * Frontend asset enqueueing and JavaScript configuration.
 *
 * ✨ INCLUDES ZERO-DELAY LOADER + WARNING ALERT (NO BLOCKING) ✨
 *
 * @package RingoCheckout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_enqueue_scripts', 'ringo_enqueue_frontend_assets', 20 );

function ringo_enqueue_frontend_assets() {
	$is_add_boat  = is_page( 'add-boat' );
	$uri          = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
	$path         = wp_parse_url( $uri, PHP_URL_PATH );
	$path         = is_string( $path ) ? untrailingslashit( $path ) : '';
	$is_pay_later = ( false !== strpos( $path, '/account/edit-post' ) );
	$is_account   = (bool) preg_match( '#/account$#', $path );

	if ( ! $is_add_boat && ! $is_pay_later && ! $is_account ) {
		return;
	}

	wp_enqueue_script( 'jquery' );
	wp_enqueue_script( 'stripe-js', 'https://js.stripe.com/v3/', [], null, true );

	$pp            = ringo_get_paypal_active_credentials();
	$has_paypal_id = ! empty( $pp['client_id'] );

	if ( $has_paypal_id ) {
		$paypal_sdk_url = add_query_arg(
			[
				'client-id' => rawurlencode( $pp['client_id'] ),
				'currency'  => 'USD',
				'intent'    => 'capture',
			],
			'https://www.paypal.com/sdk/js'
		);
		wp_enqueue_script( 'paypal-js', $paypal_sdk_url, [], null, true );
	}

	$settings = ringo_get_settings();
	$packages = [];

	foreach ( [ 'standard', 'featured', 'vip', 'pro' ] as $k ) {
		$packages[ $k ] = [
			'price'       => (float) ( $settings['prices'][ $k ] ?? 0 ),
			'description' => (string) ( $settings['descriptions'][ $k ] ?? '' ),
			'label'       => ucfirst( $k ),
		];
	}

	$addons = array_values( ringo_get_addons( true ) );

	$deps = [ 'jquery', 'stripe-js' ];
	if ( $has_paypal_id ) {
		$deps[] = 'paypal-js';
	}

	$handle = 'ringo-checkout-frontend';
	wp_register_script( $handle, '', $deps, null, true );
	wp_enqueue_script( $handle );

	wp_add_inline_script(
		$handle,
		'window.ringoPay = ' . wp_json_encode( [
			'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
			'nonceStripe'   => wp_create_nonce( 'ringo_stripe_intent_nonce' ),
			'noncePaypal'   => wp_create_nonce( 'ringo_paypal_nonce' ),
			'nonceAdmin'    => wp_create_nonce( 'ringo_admin_bypass_nonce' ),
			'nonceCoupon'   => wp_create_nonce( 'ringo_coupon_nonce' ),
			'nonceUnpaid'   => wp_create_nonce( 'ringo_mark_unpaid_nonce' ),
			'nonceFailure'  => wp_create_nonce( 'ringo_payment_failure_nonce' ),
			'nonceNative'   => wp_create_nonce( 'ringo_native_form_nonce' ),
			'stripePk'      => ringo_get_active_stripe_publishable(),
			'packages'      => $packages,
			'addons'        => $addons,
			'thankYouUrl'   => home_url( '/thank-you/' ),
			'paypalClientId'=> (string) ( $pp['client_id'] ?? '' ),
			'paypalMode'    => (string) ( $pp['mode'] ?? 'sandbox' ),
			'isAdmin'       => current_user_can( 'administrator' ),
			'isDealer'      => '' !== ringo_user_dealer_package(),
		] ) . ';',
		'before'
	);

	wp_add_inline_script( $handle, ringo_get_frontend_js(), 'after' );
}

function ringo_get_frontend_js() {
	return <<<'JS'
jQuery(function($){

  var FORM_NEW      = '1204';
  var FORM_PAYLATER = '37231';

  // ── ZERO-DELAY LOADER - CSS ────────────────────────────────────────────────
  
  if ($('style[data-ringo-loader]').length === 0) {
    $('head').append(
      '<style data-ringo-loader>' +
      '@keyframes ringoSpinAnimation { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }' +
      '#ringoLoadingOverlay { position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,.7); z-index:999998; display:flex; align-items:center; justify-content:center; }' +
      '#ringoLoadingOverlay > div { text-align:center; background:#fff; border-radius:12px; padding:40px; box-shadow:0 4px 20px rgba(0,0,0,.3); max-width:400px; }' +
      '.ringo-spinner { display:inline-block; position:relative; width:60px; height:60px; margin-bottom:20px; }' +
      '.ringo-spinner-inner { position:absolute; top:0; left:0; right:0; bottom:0; border:4px solid #f3f3f3; border-top:4px solid #0a6ebd; border-radius:50%; animation:ringoSpinAnimation 1s linear infinite; }' +
      '.ringo-loader-text { color:#333; margin:0; }' +
      '.ringo-loader-title { font-size:16px; font-weight:600; margin-bottom:8px; }' +
      '.ringo-loader-subtitle { color:#666; font-size:13px; }' +
      '</style>'
    );
  }

  function showLoaderZeroDelay() {
    $('#ringoLoadingOverlay').remove();
    
    var html = '<div id="ringoLoadingOverlay">' +
      '<div>' +
        '<div class="ringo-spinner"><div class="ringo-spinner-inner"></div></div>' +
        '<p class="ringo-loader-text ringo-loader-title">Processing your listing...</p>' +
        '<p class="ringo-loader-text ringo-loader-subtitle">Please wait while we upload your images</p>' +
      '</div>' +
    '</div>';
    
    $('body').append(html);
    console.log('[RINGO] Loader shown with ZERO delay');
  }

  function hideLoader() {
    $('#ringoLoadingOverlay').fadeOut(300, function() { $(this).remove(); });
    console.log('[RINGO] Loader hidden');
  }

  // ── REFRESH WARNING - Show alert when payment in progress ──────────────────

  var ringoPaymentInProgress = false;
  var ringoPaymentComplete   = false;
  var ringoActiveBoatPostId  = '';
  var ringoPaymentProvider   = '';
  var ringoPaymentStage      = 'idle';
  var ringoLastActivityAt    = Date.now();
  var ringoReportedConditions = {};
  var RINGO_STUCK_AFTER_MS   = 120000;

  $(document).on('ringo/native-submit-failed', function(){
    ringoPaymentInProgress = false;
    ringoPaymentComplete = false;
    ringoActiveBoatPostId = '';
    ringoPaymentProvider = '';
    ringoPaymentStage = 'idle';
    ringoReportedConditions = {};
    $('#ringoPayOverlay,#ringoStripeOverlay,#ringoPayPalOverlay,#ringoLoadingOverlay').remove();
  });

  function touchPaymentState(state, provider) {
    ringoPaymentStage   = state || ringoPaymentStage;
    ringoPaymentProvider = provider || ringoPaymentProvider;
    ringoLastActivityAt = Date.now();

    if (!ringoActiveBoatPostId || !window.ringoPay || !window.ringoPay.nonceFailure) return;

    $.ajax({
      url: window.ringoPay.ajaxUrl,
      method: 'POST',
      dataType: 'json',
      timeout: 10000,
      data: {
        action: 'ringo_payment_activity',
        boat_post_id: ringoActiveBoatPostId,
        state: ringoPaymentStage,
        provider: ringoPaymentProvider,
        nonce: window.ringoPay.nonceFailure
      }
    }).fail(function(){
      console.log('[RINGO] Payment activity update failed:', ringoPaymentStage);
    });
  }

  function reportPaymentCondition(condition, message, provider, stage) {
    if (!condition || !ringoActiveBoatPostId || ringoPaymentComplete) return;
    if (ringoReportedConditions[condition]) return;

    ringoReportedConditions[condition] = true;
    ringoPaymentProvider = provider || ringoPaymentProvider;
    ringoPaymentStage = stage || ringoPaymentStage;

    $.ajax({
      url: window.ringoPay.ajaxUrl,
      method: 'POST',
      dataType: 'json',
      timeout: 15000,
      data: {
        action: 'ringo_report_payment_failure',
        boat_post_id: ringoActiveBoatPostId,
        condition: condition,
        message: message || '',
        provider: ringoPaymentProvider,
        stage: ringoPaymentStage,
        nonce: window.ringoPay.nonceFailure
      }
    }).fail(function(){
      console.log('[RINGO] Could not report payment condition:', condition);
    });
  }

  function reportAbandonedWithBeacon() {
    if (!ringoPaymentInProgress || ringoPaymentComplete || !ringoActiveBoatPostId) return;
    if (ringoReportedConditions.checkout_abandoned) return;

    ringoReportedConditions.checkout_abandoned = true;
    var payload = new URLSearchParams();
    payload.append('action', 'ringo_report_payment_failure');
    payload.append('boat_post_id', ringoActiveBoatPostId);
    payload.append('condition', 'checkout_abandoned');
    payload.append('message', 'The buyer left or refreshed the page before payment completed.');
    payload.append('provider', ringoPaymentProvider || 'pending');
    payload.append('stage', ringoPaymentStage || 'unknown');
    payload.append('nonce', window.ringoPay.nonceFailure);

    try {
      if (navigator.sendBeacon) {
        var blob = new Blob([payload.toString()], {type:'application/x-www-form-urlencoded;charset=UTF-8'});
        navigator.sendBeacon(window.ringoPay.ajaxUrl, blob);
      } else {
        fetch(window.ringoPay.ajaxUrl, {method:'POST', body:payload, keepalive:true, credentials:'same-origin'});
      }
    } catch(e) {
      console.log('[RINGO] Abandon beacon failed:', e);
    }
  }

  window.addEventListener('pagehide', reportAbandonedWithBeacon);

  window.setInterval(function(){
    if (!ringoPaymentInProgress || ringoPaymentComplete || !ringoActiveBoatPostId) return;
    if ((Date.now() - ringoLastActivityAt) < RINGO_STUCK_AFTER_MS) return;

    reportPaymentCondition(
      'payment_snippet_stuck',
      'The payment interface did not advance for more than two minutes.',
      ringoPaymentProvider || 'pending',
      ringoPaymentStage
    );
  }, 15000);

  // Show warning when trying to leave or refresh during payment
  $(window).on('beforeunload', function(e) {
    if ( ringoPaymentInProgress && !ringoPaymentComplete ) {
      e.preventDefault();
      e.returnValue = '⚠️ Payment is being processed. Do not refresh or leave this page!';
      return '⚠️ Payment is being processed. Do not refresh or leave this page!';
    }
  });

  // Show alert on F5 or Ctrl+R refresh
  $(document).on('keydown', function(e) {
    if ( ringoPaymentInProgress ) {
      // F5 key
      if ( e.keyCode === 116 ) {
        alert('⚠️ Payment is being processed!\n\nDo not refresh the page. Your payment is being completed.\n\nYou will be redirected automatically.');
        return false;
      }
      // Ctrl+R or Cmd+R
      if ( (e.ctrlKey || e.metaKey) && e.keyCode === 82 ) {
        alert('⚠️ Payment is being processed!\n\nDo not refresh the page. Your payment is being completed.\n\nYou will be redirected automatically.');
        return false;
      }
    }
  });

  // ── Hook into form submit button click ──────────────────────────────────────
  // ✨ INSTANT PAY: Capture form data + show payment chooser IMMEDIATELY on
  //    click — before JetFormBuilder even starts uploading images.
  //    The modal shows a "Preparing your listing..." state with disabled
  //    buttons until on-success fires and we have the boatPostId, then
  //    the buttons activate automatically. Typically < 1 second to see modal.

  var ringoPendingFormData = null;
  var ringoBoatPostIdReady = null;   // set by on-success, activates pay buttons
  var ringoPostIdCallbacks = [];     // functions waiting for boatPostId

  function onBoatPostIdReady(fn) {
    if (ringoBoatPostIdReady) {
      fn(ringoBoatPostIdReady);
    } else {
      ringoPostIdCallbacks.push(fn);
    }
  }

  function resolveBoatPostId(boatPostId) {
    ringoBoatPostIdReady = boatPostId;
    ringoActiveBoatPostId = boatPostId;
    ringoPaymentComplete = false;
    ringoReportedConditions = {};
    touchPaymentState('chooser_ready', 'pending');
    console.log('[RINGO] boatPostId resolved:', boatPostId);
    var cbs = ringoPostIdCallbacks.splice(0);
    for (var i = 0; i < cbs.length; i++) { cbs[i](boatPostId); }
  }

  $(document).on('click', 'form[data-form-id="1204"] button[type="submit"], form[data-form-id="37231"] button[type="submit"]', function(){
    console.log('[RINGO] Submit button clicked - capturing form data & showing payment NOW');

    // Reset post-id promise for this submission
    ringoBoatPostIdReady  = null;
    ringoPostIdCallbacks  = [];

    var $form  = $(this).closest('form');
    var formId = getFormId($form);

    if ($form.hasClass('ringo-native-boat-form')) {
      if (window.tinyMCE && typeof window.tinyMCE.triggerSave === 'function') window.tinyMCE.triggerSave();
      if (formId === FORM_NEW) {
        var nativeDescription = ($form.find('[name="content"]').val() || '').toString().replace(/<[^>]*>/g, '').replace(/&nbsp;/gi, ' ').trim();
        if (!nativeDescription) {
          alert('Boat Description is required.');
          return;
        }
      }
      if ($form[0] && typeof $form[0].checkValidity === 'function' && !$form[0].checkValidity()) {
        if (typeof $form[0].reportValidity === 'function') $form[0].reportValidity();
        return;
      }
    }

    var packageName  = getValue($form, ['package', 'package_name']);
    var packagePrice = getValue($form, ['package_price']);
    var userEmail    = getValue($form, ['user_email', 'email', 'Email']);
    var addonIds     = getValue($form, ['addon_ids']);
    var addonsReviewed = getValue($form, ['addons_reviewed']) === '1';

    if (!packageName) {
      // No package selected yet — fall back to normal loader, wait for on-success
      showLoaderZeroDelay();
      ringoPendingFormData = { packageName: '', packagePrice: packagePrice, userEmail: userEmail, addonIds: addonIds, addonsReviewed: addonsReviewed, formId: formId };
      return;
    }

    // The native edit form must save all changes before checkout opens.
    if ($form.attr('data-ringo-defer-payment') === '1') {
      ringoPendingFormData = { packageName: packageName, packagePrice: packagePrice, userEmail: userEmail, addonIds: addonIds, addonsReviewed: addonsReviewed, formId: formId, deferred: true };
      return;
    }

    ringoPendingFormData = { packageName: packageName, packagePrice: packagePrice, userEmail: userEmail, addonIds: addonIds, addonsReviewed: addonsReviewed, formId: formId, instantShown: true };

    var key     = normalizeKey(packageName);
    var pkgMeta = (window.ringoPay && window.ringoPay.packages && window.ringoPay.packages[key])
      ? window.ringoPay.packages[key] : null;
    var priceNum = pkgMeta && pkgMeta.price ? parseFloat(pkgMeta.price) : parseFloat(packagePrice || 0);
    if (isNaN(priceNum)) priceNum = 0;
    var selectedAddonTotal = addonsReviewed ? getAddonTotalByIds(addonIds) : 0;
    var checkoutPrice = priceNum + selectedAddonTotal;

    // Admin bypass: show loader, let on-success handle it
    if (window.ringoPay && window.ringoPay.isAdmin) {
      showLoaderZeroDelay();
      return;
    }

    // Dealer / Dealer Plus accounts pay for their plan outside the site. With no paid add-on
    // selected there is nothing to charge, so skip the payment popup; the server publishes
    // the listing and the on-success handler redirects.
    // If add-ons are active and not yet reviewed, fall through: the add-on popup shows first and, when
    // nothing paid is chosen there, it publishes the listing itself.
    if (window.ringoPay && window.ringoPay.isDealer && !addonIds && (addonsReviewed || !getAddonCatalog().length)) {
      showLoaderZeroDelay();
      return;
    }

    // ✨ Show payment chooser immediately with boatPostId pending
    showChooserInstant({
      packageName : packageName,
      packageKey  : key,
      packageDesc : pkgMeta ? (pkgMeta.description || '') : '',
      packagePrice: checkoutPrice,
      basePackagePrice: priceNum,
      addonIds    : addonIds,
      addonsTotal: selectedAddonTotal,
      addonsReviewed: addonsReviewed,
      addonsLocked: addonsReviewed && formId === FORM_NEW,
      userEmail   : userEmail,
      boatPostId  : null,   // not known yet — will be injected by on-success
      formId      : formId
    });

    console.log('[RINGO] Payment chooser shown instantly, waiting for boatPostId...');
  });

  // ── Utilities ──────────────────────────────────────────────────────────────

  function getFormId($form){ return ($form.attr('data-form-id') || '').toString(); }

  function getValue($form, names){
    for (var i = 0; i < names.length; i++){
      var v = $form.find('[name="' + names[i] + '"]').val();
      if (v !== undefined && v !== null && v !== '') return v;
    }
    return '';
  }

  function extractPostIdFromResponse(response){
    try {
      if (response && response.data){
        return (response.data.inserted_post_id || response.data.post_id || response.data.inserted_boats || '').toString();
      }
      if (response){
        return (response.inserted_post_id || response.post_id || response.inserted_boats || '').toString();
      }
    } catch(e){}
    return '';
  }

  function normalizeKey(label){ return (label || '').toString().trim().toLowerCase().replace(/\s+/g, ' '); }

  function formatMoney(n){
    n = parseFloat(n || 0);
    if (isNaN(n)) n = 0;
    return '$' + n.toFixed(2);
  }

  function redirectAfterNativeAssets(url) {
    var upload = window.ringoNativeAssetUploadPromise;
    if (!upload || typeof upload.always !== 'function') {
      window.location.href = url;
      return;
    }

    ringoPaymentInProgress = true;
    var redirected = false;
    var finish = function(){
      if (redirected) return;
      redirected = true;
      window.location.href = url;
    };

    // Keep the current page alive so the browser does not cancel the background
    // cover/gallery upload after a very fast Stripe or PayPal payment.
    upload.always(finish);
    window.setTimeout(finish, 300000);
  }

  function renderPkgDescBullets(desc){
    desc = (desc || '').toString().trim();
    var parts = desc.split(/·|\r?\n|,/g).map(function(s){ return (s || '').trim(); }).filter(function(s){ return s.length > 0; });
    if (!parts.length && desc) parts = [desc];
    if (!parts.length) return '<div style="color:#666;font-size:13px;">—</div>';
    var ul = '<ul style="margin:0;padding-left:18px;color:#333;line-height:1.5;">';
    for (var i = 0; i < parts.length; i++){
      ul += '<li>' + $('<div>').text(parts[i]).html() + '</li>';
    }
    ul += '</ul>';
    return ul;
  }

  // ── Feature #1: Mark Payment as "Unpaid" ────────────────────────────────────

  function markPostAsUnpaid(boatPostId, packageName, packagePrice, addonIds, basePackagePrice) {
    return $.ajax({
      url: window.ringoPay.ajaxUrl,
      method: 'POST',
      dataType: 'json',
      data: {
        action: 'ringo_mark_post_unpaid',
        boat_post_id: boatPostId,
        package_name: packageName,
        package_price: packagePrice,
        base_package_price: basePackagePrice || packagePrice,
        addon_ids: Array.isArray(addonIds) ? addonIds.join(',') : (addonIds || ''),
        nonce: window.ringoPay.nonceUnpaid
      }
    });
  }

  // ── Feature #3: Background Image Upload ─────────────────────────────────────

  function extractUploadedFileIds(response) {
    var fileIds = [];
    try {
      if (response && response.data && response.data.ringo_native) {
        var nativeIds = response.data.uploaded_file_ids || [];
        return Array.isArray(nativeIds) ? nativeIds.map(function(id){ return parseInt(id, 10); }).filter(function(id){ return id > 0; }) : [];
      }
      if (response && response.data && typeof response.data === 'object') {
        for (var key in response.data) {
          var val = response.data[key];
          if (typeof val === 'string' && !isNaN(parseInt(val))) {
            fileIds.push(parseInt(val));
          } else if (Array.isArray(val)) {
            val.forEach(function(item) {
              if (typeof item === 'string' && !isNaN(parseInt(item))) {
                fileIds.push(parseInt(item));
              }
            });
          }
        }
      }
    } catch(e) {
      console.log('Could not extract file IDs:', e);
    }
    return fileIds;
  }

  function updatePostWithUploadedFiles(boatPostId, fileIds) {
    if (!fileIds || !fileIds.length) return;
    $.ajax({
      url: window.ringoPay.ajaxUrl,
      method: 'POST',
      dataType: 'json',
      data: {
        action: 'ringo_process_uploaded_images',
        boat_post_id: boatPostId,
        file_ids: fileIds.join(','),
        nonce: window.ringoPay.nonceUnpaid
      },
      error: function(xhr, status, error) {
        console.log('Background image processing error (non-blocking):', error);
      }
    });
  }

  // ── Optional add-ons step ────────────────────────────────────────────────

  function parseAddonIds(value) {
    if (Array.isArray(value)) return value.map(function(id){ return (id || '').toString().trim(); }).filter(Boolean);
    return (value || '').toString().split(',').map(function(id){ return id.trim(); }).filter(Boolean);
  }

  function getAddonCatalog() {
    return (window.ringoPay && Array.isArray(window.ringoPay.addons)) ? window.ringoPay.addons : [];
  }

  function getAddonTotalByIds(value) {
    var selected = parseAddonIds(value);
    var total = 0;
    getAddonCatalog().forEach(function(addon){
      if (selected.indexOf((addon.id || '').toString()) !== -1) {
        total += parseFloat(addon.price || 0) || 0;
      }
    });
    return total;
  }

  function renderSelectedAddonSummary(ctx) {
    var catalog = getAddonCatalog();
    var selected = parseAddonIds(ctx.addonIds);
    if (!selected.length) return '';
    var labels = [];
    catalog.forEach(function(addon){
      if (selected.indexOf((addon.id || '').toString()) !== -1) labels.push(addon.name || addon.id);
    });
    return labels.length ? '<div style="color:#555;margin-top:4px;font-size:12px;">Add-ons: ' + $('<div>').text(labels.join(', ')).html() + '</div>' : '';
  }

  function showAddonWindow(ctx, next) {
    var catalog = getAddonCatalog();
    ctx.basePackagePrice = parseFloat(ctx.basePackagePrice || ctx.packagePrice || 0);
    if (isNaN(ctx.basePackagePrice)) ctx.basePackagePrice = 0;

    if (!catalog.length) {
      ctx.addonsReviewed = true;
      ctx.addonIds = [];
      ctx.addonsTotal = 0;
      ctx.packagePrice = ctx.basePackagePrice;
      ctx.originalPrice = ctx.packagePrice;
      next(ctx);
      return;
    }

    ringoPaymentInProgress = true;
    var selected = parseAddonIds(ctx.addonIds);
    var rows = '';
    catalog.forEach(function(addon){
      var id = (addon.id || '').toString();
      var checked = selected.indexOf(id) !== -1 ? ' checked' : '';
      rows += '<label style="display:flex;gap:12px;align-items:flex-start;border:1px solid #dce6ee;border-radius:9px;padding:12px;cursor:pointer;background:#fff;">' +
        '<input type="checkbox" class="ringo-addon-choice" value="' + $('<div>').text(id).html() + '" data-price="' + parseFloat(addon.price || 0).toFixed(2) + '" data-effect="' + $('<div>').text((addon.field_effect || 'none').toString()).html() + '"' + checked + ' style="margin-top:4px;">' +
        '<span style="flex:1;"><strong style="display:block;color:#112f49;">' + $('<div>').text(addon.name || id).html() + '</strong>' +
        '<small style="display:block;color:#607387;margin-top:3px;line-height:1.4;">' + $('<div>').text(addon.description || '').html() + '</small></span>' +
        '<strong style="white-space:nowrap;color:#0876b9;">' + formatMoney(addon.price || 0) + '</strong>' +
      '</label>';
    });

    var html = '<div id="ringoPayOverlay" style="position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:999999;display:flex;align-items:center;justify-content:center;padding:12px;overflow-y:auto;">' +
      '<div style="width:100%;max-width:560px;background:#f8fbfd;border-radius:12px;padding:22px;font-family:Arial;box-sizing:border-box;max-height:90vh;overflow:auto;">' +
        '<div style="font-size:12px;font-weight:800;letter-spacing:.08em;color:#0876b9;text-transform:uppercase;margin-bottom:6px;">Optional upgrades</div>' +
        '<h3 style="margin:0 0 6px;font-size:22px;color:#102d46;">Add more exposure to your listing</h3>' +
        '<p style="margin:0 0 16px;color:#5d7185;font-size:14px;">Choose any add-ons below or continue without them.</p>' +
        '<div style="display:grid;gap:9px;">' + rows + '</div>' +
        '<div id="ringoAddonSlotsNote" style="display:none;margin-top:12px;padding:10px 12px;border-radius:7px;background:#eaf5fc;color:#27506f;font-size:13px;line-height:1.4;">Extra photo and video slots unlock on your listing\'s edit page as soon as payment is complete.</div>' +
        '<div style="margin-top:16px;border-top:1px solid #dce6ee;padding-top:14px;display:grid;gap:5px;color:#33495d;font-size:14px;">' +
          '<div style="display:flex;justify-content:space-between;"><span>Listing package</span><strong>' + formatMoney(ctx.basePackagePrice) + '</strong></div>' +
          '<div style="display:flex;justify-content:space-between;"><span>Selected add-ons</span><strong id="ringoAddonTotal">$0.00</strong></div>' +
          '<div style="display:flex;justify-content:space-between;font-size:17px;color:#102d46;"><span>Total</span><strong id="ringoAddonGrandTotal">' + formatMoney(ctx.basePackagePrice) + '</strong></div>' +
        '</div>' +
        '<div style="display:flex;gap:10px;margin-top:18px;flex-wrap:wrap;">' +
          '<button type="button" id="ringoSkipAddons" style="flex:1;min-width:150px;padding:12px;border:1px solid #9cafbf;background:#fff;color:#27445d;border-radius:7px;cursor:pointer;font-weight:700;">No Thanks</button>' +
          '<button type="button" id="ringoContinueAddons" style="flex:1.4;min-width:180px;padding:12px;border:1px solid #0876b9;background:#0876b9;color:#fff;border-radius:7px;cursor:pointer;font-weight:800;">Continue to Payment</button>' +
        '</div>' +
      '</div>' +
    '</div>';

    $('body').append(html);

    function calculateSelection() {
      var ids = [];
      var total = 0;
      var unlocksSlots = false;
      $('.ringo-addon-choice:checked').each(function(){
        ids.push(($(this).val() || '').toString());
        total += parseFloat($(this).attr('data-price') || 0) || 0;
        var effect = ($(this).attr('data-effect') || 'none').toString();
        if (effect === 'gallery_images' || effect === 'video_fields') unlocksSlots = true;
      });
      $('#ringoAddonSlotsNote').toggle(unlocksSlots);
      $('#ringoAddonTotal').text(formatMoney(total));
      $('#ringoAddonGrandTotal').text(formatMoney(ctx.basePackagePrice + total));
      if (window.ringoPay && window.ringoPay.isDealer) $('#ringoContinueAddons').text(ids.length ? 'Continue to Payment' : 'Publish Boat');
      return { ids: ids, total: total };
    }

    $('.ringo-addon-choice').on('change', calculateSelection);
    calculateSelection();

    function finishAddons(skip) {
      var result = skip ? {ids:[], total:0} : calculateSelection();
      ctx.addonIds = result.ids;
      ctx.addonsTotal = result.total;
      ctx.packagePrice = ctx.basePackagePrice + result.total;
      ctx.originalPrice = ctx.packagePrice;
      ctx.couponCode = '';
      ctx.couponLabel = '';
      ctx.addonsReviewed = true;
      if (ringoPendingFormData) ringoPendingFormData.addonIds = result.ids;
      // Dealer with no paid add-on left: nothing to pay, so publish instead of showing a $0 popup.
      if (window.ringoPay && window.ringoPay.isDealer && !result.ids.length) {
        $('#ringoPayOverlay').remove();
        showLoaderZeroDelay();
        // The draft ID may still be coming back from the first request: wait for it.
        onBoatPostIdReady(function(readyId){
          ctx.boatPostId = readyId;
          $.post(window.ringoPay.ajaxUrl, { action:'ringo_dealer_publish_free', nonce:window.ringoPay.nonceStripe, boat_post_id:readyId }, function(res){
            if (res && res.success && res.data && res.data.redirect) { ringoPaymentComplete = true; ringoPaymentInProgress = false; window.location.href = res.data.redirect; }
            else { hideLoader(); alert((res && res.data && res.data.message) || 'Could not publish the listing.'); }
          }).fail(function(){ hideLoader(); alert('Request failed. Please try again.'); });
        });
        return;
      }
      $('#ringoPayOverlay').remove();
      next(ctx);
    }

    $('#ringoSkipAddons').on('click', function(){ finishAddons(true); });
    $('#ringoContinueAddons').on('click', function(){ finishAddons(false); });
  }

  // ── Instant payment chooser (shown before boatPostId is known) ───────────────
  // Shows the modal immediately on form submit. Payment choices are visible
  // and clickable at once. If the Draft ID is still being created, the selected
  // method opens automatically as soon as the lightweight first request ends.

  function showChooserInstant(ctx) {
    if (!ctx.addonsReviewed) { showAddonWindow(ctx, showChooserInstant); return; }
    ringoPaymentInProgress = true;
    touchPaymentState('chooser_ready', 'pending');

    if (!ctx.originalPrice) ctx.originalPrice = ctx.packagePrice;
    ctx.couponCode = ctx.couponCode || '';

    var safePkg      = window.ringoPay && window.ringoPay.isDealer ? 'Listing add-ons' : $('<div>').text(ctx.packageName || '').html();
    var priceDisplay = '<strong>' + formatMoney(ctx.packagePrice) + '</strong>';
    var editAddonsButton = getAddonCatalog().length && !ctx.addonsLocked
      ? '<button type="button" id="ringoEditAddons" style="margin-top:9px;padding:0;border:0;background:transparent;color:#0876b9;text-decoration:underline;cursor:pointer;font-size:13px;font-weight:700;">Edit add-ons</button>'
      : '';

    var html = '<div id="ringoPayOverlay" style="position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:999999;display:flex;align-items:center;justify-content:center;padding:12px;overflow-y:auto;">' +
      '<div style="width:100%;max-width:460px;background:#fff;border-radius:10px;padding:20px;font-family:Arial;box-sizing:border-box;">' +
        '<h3 style="margin:0 0 8px 0;font-size:18px;">Choose payment method</h3>' +
        '<div style="margin:0 0 12px 0;color:#333;line-height:1.4;">' +
          '<div style="font-weight:700;color:#111;text-transform:uppercase;">' + safePkg + '</div>' +
          '<div style="color:#444;margin-top:4px;" id="ringoPriceDisplay">Total: ' + priceDisplay + '</div>' +
          renderSelectedAddonSummary(ctx) +
          editAddonsButton +
        '</div>' +

        '<div style="margin:0 0 14px 0;">' +
          '<label style="font-size:13px;font-weight:600;color:#333;display:block;margin-bottom:6px;">Have a coupon code?</label>' +
          '<div style="display:flex;gap:6px;">' +
            '<input type="text" id="ringoCouponInput" placeholder="Enter code" value="" style="flex:1;padding:8px 10px;border:1px solid #ddd;border-radius:6px;font-size:14px;text-transform:uppercase;" />' +
            '<button type="button" id="ringoCouponApply" style="padding:8px 14px;border:1px solid #555;background:#555;color:#fff;border-radius:6px;cursor:pointer;font-size:13px;">Apply</button>' +
          '</div>' +
          '<div id="ringoCouponMsg" style="margin-top:6px;font-size:13px;display:none;"></div>' +
        '</div>' +

        // Preparing notice — hidden once ready
        '<div id="ringoPreparingNotice" style="text-align:center;padding:10px 0 6px 0;font-size:13px;color:#666;">' +
          '<span style="display:inline-block;width:14px;height:14px;border:2px solid #ccc;border-top-color:#0a6ebd;border-radius:50%;animation:ringoSpinAnimation .8s linear infinite;vertical-align:middle;margin-right:6px;"></span>' +
          'Preparing your listing...' +
        '</div>' +

        '<div style="display:flex;gap:10px;flex-wrap:wrap;">' +
          '<button type="button" id="ringoPayCard" style="flex:1;min-width:130px;padding:12px;border:1px solid #0a6ebd;background:#0a6ebd;color:#fff;border-radius:6px;cursor:pointer;font-size:14px;">Credit / Debit Card</button>' +
          '<button type="button" id="ringoPayPaypal" style="flex:1;min-width:130px;padding:12px;border:1px solid #333;background:#fff;color:#111;border-radius:6px;cursor:pointer;font-size:14px;">PayPal</button>' +
        '</div>' +
        '<p style="margin:12px 0 0 0;font-size:12px;color:#666;">Do not refresh while payment is processing.</p>' +
      '</div>' +
    '</div>';

    $('body').append(html);

    function showCouponMsg(m, isError){
      var $m = $('#ringoCouponMsg');
      $m.text(m || '').show();
      if (isError) $m.css({color:'#d32f2f',background:'#ffebee',borderColor:'#ffcdd2'});
      else $m.css({color:'#388e3c',background:'#e8f5e9',borderColor:'#c8e6c9'});
    }

    $('#ringoEditAddons').on('click', function(){
      if (chooserMethodSelected) return;
      $('#ringoPayOverlay').remove();
      ctx.addonsReviewed = false;
      showAddonWindow(ctx, showChooserInstant);
    });

    $('#ringoCouponApply').on('click', function(){
      var code = ($('#ringoCouponInput').val() || '').trim().toUpperCase();
      if (!code){ showCouponMsg('Please enter a code', true); return; }
      $.ajax({
        url: window.ringoPay.ajaxUrl, method: 'POST', dataType: 'json',
        data: { action:'ringo_apply_coupon', coupon_code:code, package_price:ctx.packagePrice, user_email:ctx.userEmail||'', nonce:window.ringoPay.nonceCoupon }
      }).done(function(res){
        if (!res || !res.success || !res.data){ showCouponMsg((res&&res.data&&res.data.message)?res.data.message:'Invalid coupon',true); return; }
        ctx.couponCode   = code;
        ctx.couponLabel  = res.data.label || code;
        ctx.packagePrice = (res.data.final_price !== undefined && res.data.final_price !== null && res.data.final_price !== '') ? parseFloat(res.data.final_price) : ctx.packagePrice;
        $('#ringoPriceDisplay').html('Total: <span style="text-decoration:line-through;color:#999;">'+formatMoney(ctx.originalPrice)+'</span> <strong style="color:#0a0;">'+formatMoney(ctx.packagePrice)+'</strong> <span style="color:#0a0;font-size:12px;">('+$('<div>').text(ctx.couponLabel||'').html()+')</span>');
        showCouponMsg('Coupon applied!', false);
      }).fail(function(){ showCouponMsg('Server error checking coupon', true); });
    });

    var chooserMethodSelected = false;

    function openSelectedMethod(method) {
      if (chooserMethodSelected) return;
      chooserMethodSelected = true;
      $('#ringoPayCard, #ringoPayPaypal').prop('disabled', true).css('opacity', '0.72');
      $('#ringoPreparingNotice').show().html(
        '<span style="display:inline-block;width:14px;height:14px;border:2px solid #ccc;border-top-color:#0a6ebd;border-radius:50%;animation:ringoSpinAnimation .8s linear infinite;vertical-align:middle;margin-right:6px;"></span>' +
        'Opening secure payment...'
      );
      onBoatPostIdReady(function(boatPostId) {
        ctx.boatPostId = boatPostId;
        $('#ringoPayOverlay').remove();
        if (method === 'paypal') openPayPalModal(ctx);
        else openStripeModal(ctx);
      });
    }

    $('#ringoPayCard').on('click', function(){ openSelectedMethod('stripe'); });
    $('#ringoPayPaypal').on('click', function(){ openSelectedMethod('paypal'); });

    // Hide the small preparation note when the Draft ID arrives. The payment
    // buttons never use the old long gray disabled state.
    onBoatPostIdReady(function(boatPostId) {
      ctx.boatPostId = boatPostId;
      if (!chooserMethodSelected) $('#ringoPreparingNotice').fadeOut(150);
    });
  }

  // ── Payment-method chooser overlay ──────────────────────────────────────────

  function showChooser(ctx){
    if (!ctx.addonsReviewed) { showAddonWindow(ctx, showChooser); return; }
    // ✨ ENABLE PAYMENT WARNING - User is choosing payment method
    ringoPaymentInProgress = true;
    touchPaymentState('chooser_ready', 'pending');

    if (!ctx.originalPrice) ctx.originalPrice = ctx.packagePrice;
    ctx.couponCode = ctx.couponCode || '';

    var safePkg = window.ringoPay && window.ringoPay.isDealer ? 'Listing add-ons' : $('<div>').text(ctx.packageName || '').html();
    var editAddonsButton = getAddonCatalog().length && !ctx.addonsLocked
      ? '<button type="button" id="ringoEditAddons" style="margin-top:9px;padding:0;border:0;background:transparent;color:#0876b9;text-decoration:underline;cursor:pointer;font-size:13px;font-weight:700;">Edit add-ons</button>'
      : '';

    var priceDisplay = ctx.couponCode
      ? '<span style="text-decoration:line-through;color:#999;">' + formatMoney(ctx.originalPrice) + '</span> <strong style="color:#0a0;">' + formatMoney(ctx.packagePrice) + '</strong> <span style="color:#0a0;font-size:12px;">(' + $('<div>').text(ctx.couponLabel||'').html() + ')</span>'
      : '<strong>' + formatMoney(ctx.packagePrice) + '</strong>';

    var html = '<div id="ringoPayOverlay" style="position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:999999;display:flex;align-items:center;justify-content:center;padding:12px;overflow-y:auto;">' +
      '<div style="width:100%;max-width:460px;background:#fff;border-radius:10px;padding:20px;font-family:Arial;box-sizing:border-box;">' +
        '<h3 style="margin:0 0 8px 0;font-size:18px;">Choose payment method</h3>' +
        '<div style="margin:0 0 12px 0;color:#333;line-height:1.4;">' +
          '<div style="font-weight:700;color:#111;text-transform:uppercase;">' + safePkg + '</div>' +
          '<div style="color:#444;margin-top:4px;" id="ringoPriceDisplay">Total: ' + priceDisplay + '</div>' +
          renderSelectedAddonSummary(ctx) +
          editAddonsButton +
        '</div>' +

        '<div style="margin:0 0 14px 0;">' +
          '<label style="font-size:13px;font-weight:600;color:#333;display:block;margin-bottom:6px;">Have a coupon code?</label>' +
          '<div style="display:flex;gap:6px;">' +
            '<input type="text" id="ringoCouponInput" placeholder="Enter code" value="' + $('<div>').text(ctx.couponCode).html() + '" style="flex:1;padding:8px 10px;border:1px solid #ddd;border-radius:6px;font-size:14px;text-transform:uppercase;" />' +
            '<button type="button" id="ringoCouponApply" style="padding:8px 14px;border:1px solid #555;background:#555;color:#fff;border-radius:6px;cursor:pointer;font-size:13px;">Apply</button>' +
          '</div>' +
          '<div id="ringoCouponMsg" style="margin-top:6px;font-size:13px;display:none;"></div>' +
        '</div>' +

        '<div style="display:flex;gap:10px;flex-wrap:wrap;">' +
          '<button type="button" id="ringoPayCard" style="flex:1;min-width:130px;padding:12px;border:1px solid #0a6ebd;background:#0a6ebd;color:#fff;border-radius:6px;cursor:pointer;font-size:14px;">Credit / Debit Card</button>' +
          '<button type="button" id="ringoPayPaypal" style="flex:1;min-width:130px;padding:12px;border:1px solid #333;background:#fff;color:#111;border-radius:6px;cursor:pointer;font-size:14px;">PayPal</button>' +
        '</div>' +
        '<p style="margin:12px 0 0 0;font-size:12px;color:#666;">Do not refresh while payment is processing.</p>' +
      '</div>' +
    '</div>';

    $('body').append(html);

    function showCouponMsg(m, isError){
      var $m = $('#ringoCouponMsg');
      $m.text(m || '').show();
      if (isError) $m.css('color', '#d32f2f').css('background', '#ffebee').css('border-color', '#ffcdd2');
      else $m.css('color', '#388e3c').css('background', '#e8f5e9').css('border-color', '#c8e6c9');
    }

    $('#ringoEditAddons').on('click', function(){
      $('#ringoPayOverlay').remove();
      ctx.addonsReviewed = false;
      showAddonWindow(ctx, showChooser);
    });

    $('#ringoCouponApply').on('click', function(){
      var code = ($('#ringoCouponInput').val() || '').trim().toUpperCase();
      if (!code){ showCouponMsg('Please enter a code', true); return; }
      $.ajax({
        url: window.ringoPay.ajaxUrl,
        method: 'POST',
        dataType: 'json',
        data: {
          action: 'ringo_apply_coupon',
          coupon_code: code,
          package_price: ctx.packagePrice,
          base_package_price: ctx.basePackagePrice || ctx.packagePrice,
          addon_ids: parseAddonIds(ctx.addonIds).join(','),
          user_email: ctx.userEmail || '',
          nonce: window.ringoPay.nonceCoupon
        }
      }).done(function(res){
        if (!res || !res.success || !res.data){
          showCouponMsg((res && res.data && res.data.message) ? res.data.message : 'Invalid coupon', true);
          return;
        }
        ctx.couponCode = code;
        ctx.couponLabel = res.data.label || code;
        ctx.packagePrice = (res.data.final_price !== undefined && res.data.final_price !== null && res.data.final_price !== '') ? parseFloat(res.data.final_price) : ctx.packagePrice;
        $('#ringoPriceDisplay').html('Total: <span style="text-decoration:line-through;color:#999;">' + formatMoney(ctx.originalPrice) + '</span> <strong style="color:#0a0;">' + formatMoney(ctx.packagePrice) + '</strong> <span style="color:#0a0;font-size:12px;">(' + $('<div>').text(ctx.couponLabel||'').html() + ')</span>');
        showCouponMsg('Coupon applied!', false);
      }).fail(function(){
        showCouponMsg('Server error checking coupon', true);
      });
    });

    $('#ringoPayCard').on('click', function(){
      $('#ringoPayOverlay').remove();
      openStripeModal(ctx);
    });

    $('#ringoPayPaypal').on('click', function(){
      $('#ringoPayOverlay').remove();
      openPayPalModal(ctx);
    });
  }

  // ── Stripe: popup modal card form ──────────────────────────────────────────

  function openStripeModal(ctx){
    if (!window.Stripe){
      reportPaymentCondition('payment_setup_error', 'Stripe.js did not load.', 'stripe', 'stripe_modal_open');
      alert('Stripe is not loaded. Please refresh and try again.');
      return;
    }

    ringoPaymentInProgress = true;
    touchPaymentState('stripe_modal_open', 'stripe');

    var safePkg = window.ringoPay && window.ringoPay.isDealer ? 'Listing add-ons' : $('<div>').text(ctx.packageName || '').html();

    var html = '<div id="ringoStripeOverlay" style="position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:999999;display:flex;align-items:center;justify-content:center;padding:12px;overflow-y:auto;">' +
      '<div style="width:100%;max-width:460px;background:#fff;border-radius:10px;padding:20px;font-family:Arial;box-sizing:border-box;">' +
        '<h3 style="margin:0 0 16px 0;font-size:18px;">Card Details</h3>' +
        '<div style="border:1px solid #eee;border-radius:8px;padding:12px;background:#fafafa;margin-bottom:16px;">' +
          '<div style="font-weight:700;color:#111;text-transform:uppercase;">' + safePkg + '</div>' +
          '<div style="color:#444;margin-top:4px;">Total: <strong>' + formatMoney(ctx.packagePrice) + '</strong></div>' +
          renderSelectedAddonSummary(ctx) +
          (window.ringoPay && window.ringoPay.isDealer ? '' :
          '<div style="margin-top:10px;"><div style="font-weight:700;color:#111;margin-bottom:6px;font-size:12px;">Package Description</div>' +
          '<div>' + renderPkgDescBullets(ctx.packageDesc || '') + '</div></div>') +
        '</div>' +
        '<div id="card-number" style="border:1px solid #ddd;border-radius:6px;padding:10px;margin-bottom:12px;background:#fff;"></div>' +
        '<div id="card-expiry" style="border:1px solid #ddd;border-radius:6px;padding:10px;margin-bottom:12px;background:#fff;"></div>' +
        '<div id="card-cvc" style="border:1px solid #ddd;border-radius:6px;padding:10px;margin-bottom:12px;background:#fff;"></div>' +
        '<input type="text" id="ringoCardName" placeholder="Full Name" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;margin-bottom:12px;box-sizing:border-box;font-size:14px;" />' +
        '<div id="ringoStripeMsg" style="display:none;margin:0 0 12px 0;padding:10px;border-radius:6px;background:#fff3cd;border:1px solid #ffeeba;color:#856404;font-size:13px;"></div>' +
        '<button type="button" id="ringoStripePayBtn" style="width:100%;padding:12px;border:1px solid #0a6ebd;background:#0a6ebd;color:#fff;border-radius:6px;cursor:pointer;font-size:14px;font-weight:700;">Pay ' + formatMoney(ctx.packagePrice) + '</button>' +
        '<button type="button" id="ringoStripeBack" style="width:100%;margin-top:8px;padding:10px;border:1px solid #333;background:#fff;color:#111;border-radius:6px;cursor:pointer;">← Back</button>' +
        '<p style="margin:12px 0 0 0;font-size:12px;color:#d32f2f;font-weight:bold;">⚠️ Do not refresh or close this window!</p>' +
      '</div>' +
    '</div>';

    $('body').append(html);

    var stripe = Stripe(window.ringoPay.stripePk);
    var elements = stripe.elements();
    var cardNumberEl = elements.create('cardNumber');
    var cardExpiryEl = elements.create('cardExpiry');
    var cardCvcEl = elements.create('cardCvc');

    cardNumberEl.mount('#card-number');
    cardExpiryEl.mount('#card-expiry');
    cardCvcEl.mount('#card-cvc');

    function showStripeMsg(m){ $('#ringoStripeMsg').text(m || '').show(); }

    $('#ringoStripeBack').on('click', function(){
      $('#ringoStripeOverlay').remove();
      ringoPaymentInProgress = false;
      touchPaymentState('chooser_ready', 'pending');
      showChooser(ctx);
    });

    $('#ringoStripePayBtn').on('click', function(){
      $('#ringoStripePayBtn').prop('disabled', true).css('opacity', '0.6');
      showStripeMsg('');

      var name = ($('#ringoCardName').val() || '').trim();
      if (!name){
        $('#ringoStripePayBtn').prop('disabled', false).css('opacity', 1);
        showStripeMsg('Please enter your name');
        return;
      }

      touchPaymentState('stripe_intent_request', 'stripe');

      $.ajax({
        url: window.ringoPay.ajaxUrl,
        method: 'POST',
        dataType: 'json',
        timeout: 30000,
        data: {
          action: 'ringo_stripe_create_intent',
          package_name:  ctx.packageName,
          package_price: ctx.packagePrice,
          base_package_price: ctx.basePackagePrice || ctx.packagePrice,
          addon_ids: parseAddonIds(ctx.addonIds).join(','),
          user_email:    ctx.userEmail || '',
          boat_post_id:  ctx.boatPostId,
          form_id:       ctx.formId,
          coupon_code:   ctx.couponCode || '',
          nonce:         window.ringoPay.nonceStripe
        }
      }).done(function(res){
        // 100%-off coupon: the server already completed the listing, no card needed.
        if (res && res.success && res.data && res.data.free_checkout && res.data.redirect){
          ringoPaymentComplete = true;
          ringoPaymentInProgress = false;
          window.location.href = res.data.redirect;
          return;
        }
        if (!res || !res.success || !res.data || !res.data.client_secret){
          var startMessage = (res && res.data && res.data.message) ? res.data.message : 'Could not start payment.';
          $('#ringoStripePayBtn').prop('disabled', false).css('opacity', 1);
          showStripeMsg(startMessage);
          ringoPaymentInProgress = false;
          reportPaymentCondition(
            (res && res.data && res.data.condition) ? res.data.condition : 'stripe_intent_error',
            startMessage,
            'stripe',
            'stripe_intent_request'
          );
          return;
        }

        var piId = res.data.payment_intent_id;
        touchPaymentState('stripe_confirming', 'stripe');

        stripe.confirmCardPayment(res.data.client_secret, {
          payment_method: {
            card: cardNumberEl,
            billing_details: { name: name, email: (ctx.userEmail || undefined) }
          }
        }).then(function(result){
          if (result.error){
            var isDecline = result.error.code === 'card_declined' || !!result.error.decline_code;
            var condition = isDecline ? 'card_rejected' : 'stripe_confirmation_error';
            var errorMessage = result.error.message || 'Payment failed. Please try again.';

            $('#ringoStripePayBtn').prop('disabled', false).css('opacity', 1);
            showStripeMsg(errorMessage);
            ringoPaymentInProgress = false;

            $.ajax({
              url: window.ringoPay.ajaxUrl,
              method: 'POST',
              dataType: 'json',
              timeout: 15000,
              data: {
                action: 'ringo_mark_payment_failed',
                boat_post_id: ctx.boatPostId,
                payment_intent_id: piId,
                error_message: errorMessage,
                condition: condition,
                nonce: window.ringoPay.nonceStripe
              }
            }).fail(function(){
              reportPaymentCondition(condition, errorMessage, 'stripe', 'stripe_confirming');
            });
            return;
          }

          var paymentStatus = result.paymentIntent && result.paymentIntent.status ? result.paymentIntent.status : '';
          if (paymentStatus === 'succeeded') {
            ringoPaymentComplete = true;
            ringoPaymentInProgress = false;
            touchPaymentState('payment_complete', 'stripe');
            redirectAfterNativeAssets(
              window.ringoPay.thankYouUrl + '?provider=stripe&payment_intent=' + encodeURIComponent(piId)
            );
            return;
          }

          var pendingStatuses = ['processing', 'requires_action', 'requires_confirmation'];
          var pending = pendingStatuses.indexOf(paymentStatus) !== -1;
          var incompleteMessage = pending
            ? 'Your payment is still pending. Please wait before trying again.'
            : 'Stripe did not return a completed payment status.';

          $('#ringoStripePayBtn').prop('disabled', false).css('opacity', 1);
          showStripeMsg(incompleteMessage);
          ringoPaymentInProgress = false;
          reportPaymentCondition(
            pending ? 'payment_pending' : 'stripe_payment_incomplete',
            incompleteMessage + (paymentStatus ? ' Status: ' + paymentStatus : ''),
            'stripe',
            'stripe_confirming'
          );
        }).catch(function(err){
          var message = err && err.message ? err.message : 'Stripe confirmation stopped unexpectedly.';
          $('#ringoStripePayBtn').prop('disabled', false).css('opacity', 1);
          showStripeMsg(message);
          ringoPaymentInProgress = false;
          reportPaymentCondition('stripe_confirmation_error', message, 'stripe', 'stripe_confirming');
        });

      }).fail(function(xhr, textStatus, errorThrown){
        var timedOut = textStatus === 'timeout';
        var message = timedOut ? 'Stripe gateway request timed out.' : 'Server error while starting Stripe payment.';
        $('#ringoStripePayBtn').prop('disabled', false).css('opacity', 1);
        showStripeMsg(message);
        ringoPaymentInProgress = false;
        reportPaymentCondition(timedOut ? 'gateway_timeout' : 'stripe_intent_error', message + (errorThrown ? ' ' + errorThrown : ''), 'stripe', 'stripe_intent_request');
      });
    });
  }

  // ── PayPal: popup buttons modal ─────────────────────────────────────────────

  function openPayPalModal(ctx){
    if (!window.paypal){
      reportPaymentCondition('payment_setup_error', 'PayPal SDK did not load.', 'paypal', 'paypal_modal_open');
      alert('PayPal is not configured. Please contact admin.');
      return;
    }

    ringoPaymentInProgress = true;
    touchPaymentState('paypal_modal_open', 'paypal');

    var safePkg = window.ringoPay && window.ringoPay.isDealer ? 'Listing add-ons' : $('<div>').text(ctx.packageName || '').html();

    var html = '<div id="ringoPayPalOverlay" style="position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:999999;display:flex;align-items:flex-start;justify-content:center;padding:12px;overflow-y:auto;">' +
      '<div style="width:100%;max-width:960px;background:#fff;border-radius:10px;padding:16px;font-family:Arial;margin:auto;box-sizing:border-box;">' +
        '<h3 style="margin:0 0 16px 0;font-size:18px;">Pay with PayPal</h3>' +
        '<div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">' +
          '<div style="flex:1;min-width:240px;">' +
            '<div style="border:1px solid #eee;border-radius:8px;padding:12px;background:#fafafa;">' +
              (window.ringoPay && window.ringoPay.isDealer ? '' : '<div style="font-weight:700;color:#111;margin-bottom:6px;">Package Name</div>') +
              '<div style="font-weight:700;color:#111;text-transform:uppercase;">' + safePkg + '</div>' +
              '<div style="margin-top:4px;color:#444;">Total: <strong>' + formatMoney(ctx.packagePrice) + '</strong></div>' +
              renderSelectedAddonSummary(ctx) +
              (window.ringoPay && window.ringoPay.isDealer ? '' :
              '<div style="margin-top:10px;"><div style="font-weight:700;color:#111;margin-bottom:6px;">Package Description</div>' +
              '<div>' + renderPkgDescBullets(ctx.packageDesc || '') + '</div></div>') +
            '</div>' +
            '<div style="margin-top:14px;">' +
              '<button type="button" id="ringoPayPalBack" style="width:100%;padding:10px 12px;border:1px solid #333;background:#fff;color:#111;border-radius:6px;cursor:pointer;">← Back</button>' +
            '</div>' +
          '</div>' +
          '<div style="flex:1;min-width:240px;max-height:80vh;overflow-y:auto;padding-right:4px;">' +
            '<div id="ringoPayPalMsg" style="display:none;margin:0 0 10px 0;padding:10px;border-radius:6px;background:#fff3cd;border:1px solid #ffeeba;color:#856404;font-size:13px;"></div>' +
            '<div id="paypal-button-container"></div>' +
            '<p style="margin:12px 0 0 0;font-size:12px;color:#d32f2f;font-weight:bold;">⚠️ Do not refresh or close this window!</p>' +
          '</div>' +
        '</div>' +
      '</div>' +
    '</div>';

    $('body').append(html);

    $('#ringoPayPalBack').on('click', function(){
      $('#ringoPayPalOverlay').remove();
      ringoPaymentInProgress = false;
      touchPaymentState('chooser_ready', 'pending');
      showChooser(ctx);
    });

    function showPPMsg(m){ $('#ringoPayPalMsg').text(m || '').show(); }

    paypal.Buttons({
      createOrder: function(){
        touchPaymentState('paypal_order_request', 'paypal');
        return $.ajax({
          url: window.ringoPay.ajaxUrl,
          method: 'POST',
          dataType: 'json',
          timeout: 30000,
          data: {
            action: 'ringo_paypal_create_order',
            package_name:  ctx.packageName,
            package_price: ctx.packagePrice,
            base_package_price: ctx.basePackagePrice || ctx.packagePrice,
            addon_ids: parseAddonIds(ctx.addonIds).join(','),
            user_email:    ctx.userEmail,
            boat_post_id:  ctx.boatPostId,
            form_id:       ctx.formId,
            coupon_code:   ctx.couponCode || '',
            nonce:         window.ringoPay.noncePaypal
          }
        }).then(function(res){
          // 100%-off coupon: the server already completed the listing, no PayPal order needed.
          if (res && res.success && res.data && res.data.free_checkout && res.data.redirect){
            ringoPaymentComplete = true;
            ringoPaymentInProgress = false;
            window.location.href = res.data.redirect;
            return new Promise(function(){});
          }
          if (!res || !res.success || !res.data || !res.data.order_id){
            var message = (res && res.data && res.data.message) ? res.data.message : 'Could not start PayPal checkout.';
            showPPMsg(message);
            ringoPaymentInProgress = false;
            reportPaymentCondition(
              (res && res.data && res.data.condition) ? res.data.condition : 'paypal_order_error',
              message,
              'paypal',
              'paypal_order_request'
            );
            throw new Error(message);
          }
          return res.data.order_id;
        }, function(xhr, textStatus, errorThrown){
          var timedOut = textStatus === 'timeout';
          var message = timedOut ? 'PayPal gateway request timed out.' : 'PayPal did not respond while creating the order.';
          showPPMsg(message);
          ringoPaymentInProgress = false;
          reportPaymentCondition(timedOut ? 'gateway_timeout' : 'paypal_no_response', message + (errorThrown ? ' ' + errorThrown : ''), 'paypal', 'paypal_order_request');
          throw new Error(message);
        });
      },

      onApprove: function(data){
        showPPMsg('Processing…');
        touchPaymentState('paypal_approved', 'paypal');
        touchPaymentState('paypal_capturing', 'paypal');
        return $.ajax({
          url: window.ringoPay.ajaxUrl,
          method: 'POST',
          dataType: 'json',
          timeout: 30000,
          data: {
            action: 'ringo_paypal_capture_order',
            order_id:    data.orderID,
            boat_post_id: ctx.boatPostId,
            nonce:        window.ringoPay.noncePaypal
          }
        }).then(function(res){
          if (res && res.success && res.data && res.data.redirect){
            ringoPaymentComplete = true;
            ringoPaymentInProgress = false;
            touchPaymentState('payment_complete', 'paypal');
            redirectAfterNativeAssets(res.data.redirect);
            return;
          }

          var message = (res && res.data && res.data.message) ? res.data.message : 'PayPal capture failed.';
          var condition = (res && res.data && res.data.condition) ? res.data.condition : 'paypal_capture_error';
          showPPMsg(message);
          ringoPaymentInProgress = false;
          reportPaymentCondition(condition, message, 'paypal', 'paypal_capturing');
        }, function(xhr, textStatus, errorThrown){
          var timedOut = textStatus === 'timeout';
          var message = timedOut ? 'PayPal capture request timed out.' : 'PayPal did not respond while completing payment.';
          showPPMsg(message);
          ringoPaymentInProgress = false;
          reportPaymentCondition(timedOut ? 'gateway_timeout' : 'paypal_no_response', message + (errorThrown ? ' ' + errorThrown : ''), 'paypal', 'paypal_capturing');
        });
      },

      onCancel: function(){
        showPPMsg('Payment was cancelled.');
        ringoPaymentInProgress = false;
        reportPaymentCondition('payment_cancelled', 'The buyer cancelled PayPal before payment completed.', 'paypal', ringoPaymentStage);
      },

      onError: function(err){
        console.error(err);
        var message = err && err.message ? err.message : 'PayPal payment window error.';
        showPPMsg('PayPal error. Please try again.');
        ringoPaymentInProgress = false;
        reportPaymentCondition('paypal_sdk_error', message, 'paypal', ringoPaymentStage);
      }

    }).render('#paypal-button-container').catch(function(err){
      var message = err && err.message ? err.message : 'PayPal buttons could not render.';
      ringoPaymentInProgress = false;
      reportPaymentCondition('paypal_sdk_error', message, 'paypal', 'paypal_modal_open');
    });
  }

  // ── JetFormBuilder success hook ─────────────────────────────────────────────
  // ✨ INSTANT PAY: on-success fires after JetFormBuilder finishes (slow).
  //    If the payment modal was already shown instantly on click, we just
  //    resolve the boatPostId promise so the buttons activate.
  //    If somehow the modal was not shown (no package selected at click time),
  //    we show it now as a fallback.

  $(document).on('jet-form-builder/ajax/on-success', function(event, response, form){
    var $form  = $(form);
    var formId = getFormId($form);

    if (formId !== FORM_NEW && formId !== FORM_PAYLATER) return;

    $('.jet-form-builder-messages-wrap').hide();

    // Extract boatPostId from the response
    var boatPostId = '';
    if (formId === FORM_NEW){
      boatPostId = extractPostIdFromResponse(response);
      if (!boatPostId) boatPostId = getValue($form, ['inserted_post_id','inserted_boats','post_id','boat_post_id','_post_id']);
    } else {
      boatPostId = getValue($form, ['_post_id','boat_post_id','post_id','inserted_post_id']);
      if (!boatPostId) boatPostId = extractPostIdFromResponse(response);
    }

    if (!boatPostId){ hideLoader(); alert('Boat post ID is missing. Please contact support.'); return; }

    // Dealer listing with no paid add-ons: already published server-side, nothing to pay.
    if (response && response.data && response.data.dealer_free && response.data.redirect) {
      ringoPendingFormData = null;
      ringoPaymentComplete = true;
      ringoPaymentInProgress = false;
      var dealerRedirect = response.data.redirect;
      // Let the background image upload start first so the redirect waits for it.
      window.setTimeout(function(){ redirectAfterNativeAssets(dealerRedirect); }, 150);
      return;
    }

    // Get package data (from click-capture or DOM fallback)
    var packageName, packagePrice, userEmail, addonIds, addonsReviewed;
    if (ringoPendingFormData && ringoPendingFormData.formId === formId) {
      packageName    = ringoPendingFormData.packageName;
      packagePrice   = ringoPendingFormData.packagePrice;
      userEmail      = ringoPendingFormData.userEmail;
      addonIds       = ringoPendingFormData.addonIds || '';
      addonsReviewed = !!ringoPendingFormData.addonsReviewed;
    } else {
      packageName    = getValue($form, ['package', 'package_name']);
      packagePrice   = getValue($form, ['package_price']);
      userEmail      = getValue($form, ['user_email', 'email', 'Email']);
      addonIds       = getValue($form, ['addon_ids']);
      addonsReviewed = getValue($form, ['addons_reviewed']) === '1';
    }
    ringoPendingFormData = null;

    // Admin bypass
    if (window.ringoPay && window.ringoPay.isAdmin) {
      $.post(window.ringoPay.ajaxUrl, {
        action:       'ringo_admin_bypass',
        nonce:        window.ringoPay.nonceAdmin,
        boat_post_id: boatPostId,
        package_name: packageName
      }, function(res) {
        if (res && res.success) {
          ringoPaymentComplete = true;
          ringoPaymentInProgress = false;
          redirectAfterNativeAssets(window.ringoPay.thankYouUrl + '?provider=admin&boat_post_id=' + boatPostId);
        } else {
          hideLoader();
          alert('Admin bypass failed: ' + (res && res.data ? res.data : 'Unknown error'));
        }
      }).fail(function() {
        hideLoader();
        alert('Admin bypass request failed. Please try again.');
      });
      return;
    }

    // Start the payment attempt before activating payment controls. The request
    // remains non-blocking for image processing, but payment state tracking must
    // exist before a very fast buyer can click Pay.
    var unpaidRequest = null;
    var paymentAlreadyStarted = !!(response && response.data && response.data.payment_started);
    if (packageName && !paymentAlreadyStarted) {
      unpaidRequest = markPostAsUnpaid(boatPostId, packageName, packagePrice, addonIds, packagePrice)
        .done(function(r){ console.log('[RINGO] Background: marked unpaid:', r); })
        .fail(function(e){ console.log('[RINGO] Background: markUnpaid failed (payment can continue):', e); });
    }

    var fileIds = extractUploadedFileIds(response);
    if (fileIds && fileIds.length) {
      updatePostWithUploadedFiles(boatPostId, fileIds);
      console.log('[RINGO] Background: image processing queued for', fileIds.length, 'file(s)');
    }

    function continueToPayment() {
      // Resolve boatPostId — activates pay buttons if modal already open.
      if ($('#ringoPayOverlay').length || $('#ringoStripeOverlay').length || $('#ringoPayPalOverlay').length) {
        console.log('[RINGO] Modal already open — resolving boatPostId:', boatPostId);
        resolveBoatPostId(boatPostId);
        return;
      }

      // Fallback: modal was not shown on click (e.g. no package at click time).
      if (!packageName){ hideLoader(); alert('Please select a package.'); return; }

      var key     = normalizeKey(packageName);
      var pkgMeta = (window.ringoPay && window.ringoPay.packages && window.ringoPay.packages[key])
        ? window.ringoPay.packages[key] : null;
      var priceNum = pkgMeta && pkgMeta.price ? parseFloat(pkgMeta.price) : parseFloat(packagePrice || 0);
      if (isNaN(priceNum) || priceNum <= 0) priceNum = parseFloat(packagePrice || 0);
      var selectedAddonTotal = addonsReviewed ? getAddonTotalByIds(addonIds) : 0;
      var checkoutPrice = priceNum + selectedAddonTotal;

      resolveBoatPostId(boatPostId);
      hideLoader();
      try {
        showChooser({
          packageName : packageName,
          packageKey  : key,
          packageDesc : pkgMeta ? (pkgMeta.description || '') : '',
          packagePrice: checkoutPrice,
          basePackagePrice: priceNum,
          addonIds    : addonIds,
          addonsTotal: selectedAddonTotal,
          addonsReviewed: addonsReviewed,
          addonsLocked: addonsReviewed && formId === FORM_NEW,
          userEmail   : userEmail,
          boatPostId  : boatPostId,
          formId      : formId
        });
      } catch(e) {
        console.error('[RINGO] ERROR showing payment chooser:', e);
        reportPaymentCondition('payment_snippet_stuck', e.message || 'Payment chooser could not open.', 'pending', 'chooser_ready');
        alert('Error: ' + e.message);
      }
    }

    if (unpaidRequest && typeof unpaidRequest.always === 'function') {
      unpaidRequest.always(continueToPayment);
    } else {
      continueToPayment();
    }
  });

});
JS;
}