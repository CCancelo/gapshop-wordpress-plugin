(function ($) {
    'use strict';
    console.log('gapshop-otp loaded, body classes:', $('body').attr('class'));

    let _email = '';

    const HTML = `
        <div class="gs-otp-wrap">
            <div id="gs-otp-step-email">
                <p class="gs-otp-hint">Enter your email to receive a login code</p>
                <input type="email" id="gs-otp-email" placeholder="your@email.com"
                       autocomplete="email">
                <button id="gs-otp-send" class="gs-otp-btn">Send Code</button>
                <p id="gs-otp-email-err" class="gs-otp-error"></p>
            </div>
            <div id="gs-otp-step-code" style="display:none">
                <p class="gs-otp-hint">Enter the 6-digit code sent to <strong id="gs-otp-sent-to"></strong></p>
                <input type="text" id="gs-otp-code" placeholder="000000"
                       maxlength="6" inputmode="numeric" autocomplete="one-time-code">
                <button id="gs-otp-verify" class="gs-otp-btn">Verify</button>
                <button id="gs-otp-back" class="gs-otp-link" type="button">Use a different email</button>
                <p id="gs-otp-code-err" class="gs-otp-error"></p>
            </div>
            <div id="gs-otp-step-done" style="display:none">
                <p class="gs-otp-hint">&#10003; Verified! Signing you in&hellip;</p>
            </div>
        </div>`;

    function init() {
        const isWpLogin    = $('body').hasClass('login');
        const isWooAccount = $('body').hasClass('woocommerce-account')
                                && $('.woocommerce-form-login').length > 0;

        if (!isWpLogin && !isWooAccount) return;

        const context = isWpLogin ? 'wp' : 'woo';
        const $container = $('<div id="gs-otp-container"></div>').attr('data-context', context);

        if (isWpLogin) {
            $('#loginform').after($container);
        } else {
            $('.woocommerce-form-login').before($container);
        }

        $container.html(HTML);
        hideNativeForm(context);
        bindEvents();
        $('#gs-otp-email').focus();
    }

    function hideNativeForm(context) {
        if (context === 'wp') {
            $('#loginform').hide();
            $('#nav, #backtoblog').hide();
        } else {
            $('.woocommerce-form-login').hide();
        }
    }

    function bindEvents() {
        $('#gs-otp-email').on('keydown', function (e) { if (e.key === 'Enter') sendCode(); });
        $('#gs-otp-send').on('click', sendCode);
        $('#gs-otp-code').on('keydown', function (e) { if (e.key === 'Enter') verifyCode(); });
        $('#gs-otp-verify').on('click', verifyCode);
        $('#gs-otp-back').on('click', function () {
            $('#gs-otp-step-code').hide();
            $('#gs-otp-step-email').show();
            $('#gs-otp-code-err').text('');
            $('#gs-otp-email').focus();
        });
    }

    function sendCode() {
        const email = $('#gs-otp-email').val().trim();
        $('#gs-otp-email-err').text('');

        if (!isValidEmail(email)) {
            $('#gs-otp-email-err').text('Please enter a valid email address.');
            return;
        }

        const $btn = $('#gs-otp-send').text('Sending\u2026').prop('disabled', true);

        $.post(gapshopOtp.ajaxUrl, {
            action: 'gapshop_otp_send',
            nonce: gapshopOtp.nonce,
            email,
        })
            .done(function (resp) {
                if (resp.success) {
                    _email = email;
                    $('#gs-otp-sent-to').text(email);
                    $('#gs-otp-step-email').hide();
                    $('#gs-otp-step-code').show();
                    $('#gs-otp-code').val('').focus();
                } else {
                    $('#gs-otp-email-err').text(resp.data || 'Failed to send code.');
                }
            })
            .fail(function () {
                $('#gs-otp-email-err').text('Network error. Please try again.');
            })
            .always(function () {
                $btn.text('Send Code').prop('disabled', false);
            });
    }

    function verifyCode() {
        const code = $('#gs-otp-code').val().trim();
        $('#gs-otp-code-err').text('');

        if (!/^\d{6}$/.test(code)) {
            $('#gs-otp-code-err').text('Please enter the 6-digit code.');
            return;
        }

        const $btn = $('#gs-otp-verify').text('Verifying\u2026').prop('disabled', true);

        $.post(gapshopOtp.ajaxUrl, {
            action: 'gapshop_otp_verify',
            nonce: gapshopOtp.nonce,
            email: _email,
            code,
        })
            .done(function (resp) {
                if (resp.success) {
                    $('#gs-otp-step-code').hide();
                    $('#gs-otp-step-done').show();
                    window.location.href = resp.data.redirect || gapshopOtp.redirect;
                } else {
                    $('#gs-otp-code-err').text(resp.data || 'Invalid code. Please try again.');
                    $btn.text('Verify').prop('disabled', false);
                }
            })
            .fail(function () {
                $('#gs-otp-code-err').text('Network error. Please try again.');
                $btn.text('Verify').prop('disabled', false);
            });
    }

    function isValidEmail(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    }

    $(document).ready(init);

}(jQuery));