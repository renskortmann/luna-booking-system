<section class="card narrow">
    <h1>Installer not enabled</h1>

    <p>
        To install the booking system from the browser, first set an install
        token in <code>app/config.php</code>:
    </p>

    <pre><code>'install_token' => 'paste-a-long-random-string-here',</code></pre>

    <p>
        Put it inside the <code>app</code> block, upload the file, then open
        this page with the token in the address:
    </p>

    <pre><code><?= e(rtrim(\Luna\Config::baseUrl(), '/')) ?>/install?token=...</code></pre>

    <p class="muted small">
        The token exists so that nobody who happens to find this address during
        the minutes between upload and installation can claim the administrator
        account. Remove it from the configuration once you are done.
    </p>
</section>
