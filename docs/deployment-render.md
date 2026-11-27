# Deploying to Render

This application needs both PHP and Node.js during deployment. Render's Node
runtime does not include Composer, so deploy it as a Docker web service instead.

1. Commit and push `Dockerfile` and `.dockerignore` to the GitHub branch Render
   deploys.
2. In Render, create a **new Web Service** for the repository and select
   **Docker** as the runtime. An existing service created with the Node runtime
   will keep using that runtime.
3. Create a Render PostgreSQL database and set these web-service environment
   variables directly in Render's **Environment** settings. Do not upload your
   local `.env` file or paste it wholesale.
   - `APP_ENV=production`
   - `APP_DEBUG=false`
   - `APP_URL=https://<your-render-service>.onrender.com`
   - `APP_KEY=<output of php artisan key:generate --show>`
   - `INITIAL_COUNSELOR_EMAIL=<email address for the initial counselor>`
   - `INITIAL_COUNSELOR_PASSWORD=<unique password of at least 8 characters>`
   - `DB_CONNECTION=pgsql`
   - `DB_URL=<the database's internal connection URL>`
   - `SESSION_DRIVER=database`
   - `CACHE_STORE=database`
   - `QUEUE_CONNECTION=database`
   - `LOG_CHANNEL=stderr`
   - `MAIL_MAILER=brevo`
   - `BREVO_API_KEY=<Brevo API v3 key>`
   - `MAIL_FROM_ADDRESS=<verified sender email>`
   - `MAIL_FROM_NAME=KajoRec`
   - `WEBPUSH_VAPID_SUBJECT=mailto:<verified-admin-email>`
   - `WEBPUSH_VAPID_PUBLIC_KEY=<VAPID public key>`
   - `WEBPUSH_VAPID_PRIVATE_KEY=<VAPID private key>`
4. Deploy the web service. The container installs PHP dependencies with
   Composer, builds the frontend assets, runs database migrations, seeds the
   initial guidance counselor if one does not already exist, and starts Laravel
   on Render's assigned port. Keep the counselor password private and change
   it after the first login.

   If no counselor account exists, sign in with username `Administrator` and
   the password configured above. The account requires a new name, email
   address, and password before it can access the system; verify the new email
   to continue. Existing counselor accounts are preserved.
5. Create a Render **Cron Job** from the same repository and branch, using the
   Docker runtime. Set its schedule to `* * * * *` and its Docker command to
   `php artisan schedule:run`. Copy the web service's required environment
   variables to the Cron Job. This runs scheduled tasks, including the
   one-hour appointment reminders and overdue appointment notifications.
6. Import the complete student list from the guidance dashboard before enabling
   student registration or teacher account requests. Teachers verify their email
   and submit an ID photo for counselor approval; they do not need to be imported
   into a teacher roster. Existing student roster rows are preserved during
   deployment but do not count as a new import. Student imports replace the
   reference roster and deactivate students missing from the uploaded file, so
   use the complete current roster.

Before pushing the repository, update the Composer lock file in an environment
with PHP and Composer:

```sh
composer update minishlink/web-push --with-all-dependencies
```

Commit both `composer.json` and `composer.lock`. The Dockerfile uses
`composer install`, so Render installs exactly what is recorded in the lock
file.

Brevo delivery uses its HTTPS API, not SMTP. Render Free web services block
outbound SMTP ports 25, 465, and 587, so configuring Brevo SMTP will not send
email from a Free service. Create an API key in Brevo and verify the sender
address before deploying. Keep the API key in Render's Environment settings;
do not commit it to the repository.

Do not use the local SQLite database for production data on Render's ephemeral
filesystem. Files uploaded to the local storage disk are also ephemeral; use
persistent storage or an object-storage service if those files must survive
redeploys.
