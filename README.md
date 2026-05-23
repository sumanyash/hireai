# HireAI

## Production Security Notes

- Set strong `CALL_WEBHOOK_SECRET` and `INTERVIEW_WEBHOOK_SECRET` values before enabling webhooks.
- Do not enable the default Super Admin seed in `schema.sql` for production. Create production admins with strong passwords through the admin panel or a secure one-time provisioning process.
- If campaign integration sync is used, set `INTEGRATION_ALLOWED_DOMAINS` to a comma-separated list of trusted webhook domains.
