<section class="card narrow">
    <h1>Installer not enabled</h1>

    <p>
        To install the Macrolab website from the browser, first set an install
        token in <code>app/config.php</code>:
    </p>

    <pre><code>'install_token' => 'paste-a-long-random-string-here',</code></pre>

    <p>
        Put it inside the <code>app</code> block, save the file on the server
        (Plesk File Manager, or FTP), then open this page with the token in the
        address - URL-encoded, since a generated token may contain
        <code>/</code>, <code>+</code> or <code>=</code>:
    </p>

    <pre><code><?= e(rtrim(\Macrolab\Config::baseUrl(), '/')) ?>/install?token=...</code></pre>

    <p class="muted small">
        The token exists so that nobody who happens to find this address during
        the minutes between deployment and installation can claim the administrator
        account. Remove it from the configuration once you are done.
    </p>
</section>
