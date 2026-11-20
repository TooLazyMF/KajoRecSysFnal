# Deploying to Render

This application needs both PHP and Node.js during deployment. Render's Node
runtime does not include Composer, so deploy it as a Docker web service instead.

1. Commit and push `Dockerfile` and `.dockerignore` to the GitHub branch Render
   deploys.
2. In Render, create a **new Web Service** for the repository and select
   **Docker** as the runtime. An existing service created with the Node runtime
   will keep using that runtime.
3. Create a Render PostgreSQL database and set these web-service environment
   variables:
   - `APP_ENV=production`
   - `APP_DEBUG=false`
   - `APP_URL=https://<your-render-service>.onrender.com`
   - `APP_KEY=<output of php artisan key:generate --show>`
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
4. Deploy. The container installs PHP dependencies with Composer, builds the
   frontend assets, runs database migrations, seeds the initial guidance
   counselor if one does not already exist, and starts Laravel on Render's
   assigned port. No service shell is needed for this setup.

   If no counselor account exists, sign in with username `Administrator` and
   password `Admin`. The account requires a new name, email address, and
   password before it can access the system; verify the new email to continue.
   Existing counselor accounts are preserved.
5. Import the complete student and teacher lists from the guidance dashboard
   before enabling student registration or creating teacher accounts. Existing
   roster rows are preserved during deployment but do not count as a new import;
   each list must be imported through the dashboard after this version is
   deployed. Student imports replace the reference roster and deactivate
   students missing from the uploaded file, so use the complete current roster.

Brevo delivery uses its HTTPS API, not SMTP. Render Free web services block
outbound SMTP ports 25, 465, and 587, so configuring Brevo SMTP will not send
email from a Free service. Create an API key in Brevo and verify the sender
address before deploying. Keep the API key in Render's Environment settings;
do not commit it to the repository.

Do not use the local SQLite database for production data on Render's ephemeral
filesystem. Files uploaded to the local storage disk are also ephemeral; use
persistent storage or an object-storage service if those files must survive
redeploys.
