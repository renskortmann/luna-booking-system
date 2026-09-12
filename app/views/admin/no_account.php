<section class="card narrow">
    <h1>No administrator yet</h1>

    <p>This installation has no administrator account.</p>

    <p>
        Open the <a href="<?= e(path('/install')) ?>">one-time setup page</a> to
        load the database schema and create it. That page needs the
        <code>install_token</code> from <code>app/config.php</code> in its
        address, and it stops working once the account exists.
    </p>

    <p class="muted small">
        If the server does give you a shell, <code>php app/cli/migrate.php</code>
        followed by <code>php app/cli/create-admin.php</code> does the same thing.
    </p>
</section>
