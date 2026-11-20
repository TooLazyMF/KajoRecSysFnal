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
   frontend assets, runs database migrations, and starts Laravel on Render's
   assigned port.
5. Once, after the first deployment, run this from the service shell to create
   the initial guidance counselor account:

   ```sh
   php artisan db:seed --class=GuidanceCounselorSeeder --force
   ```

   Sign in with username `Administrator` and password `Admin`. The account
   requires a new name, email address, and password before it can access the
   system; verify the new email to continue. Keep the roster files private and
   import the student list before allowing student registrations.

Brevo delivery uses its HTTPS API, not SMTP. Render Free web services block
outbound SMTP ports 25, 465, and 587, so configuring Brevo SMTP will not send
email from a Free service. Create an API key in Brevo and verify the sender
address before deploying. Keep the API key in Render's Environment settings;
do not commit it to the repository.

Do not use the local SQLite database for production data on Render's ephemeral
filesystem. Files uploaded to the local storage disk are also ephemeral; use
persistent storage or an object-storage service if those files must survive
redeploys.
