{{--
  EMAIL TEMPLATE — Password Setup
  -----------------------------------------------------------
  This file is the HTML template for the email that gets sent
  when an admin creates a new user in User Management.

  To preview this email during development:
  - Set MAIL_MAILER=log in .env
  - Trigger the action (e.g. create a new user)
  - Open storage/logs/laravel.log and search for "Content-Type: text/html"
  - The HTML of this email will appear there

  SMTP must be configured in .env for this email to actually send.
  Required .env values: MAIL_HOST, MAIL_PORT, MAIL_FROM_ADDRESS
--}}
<p>Hello,</p>
<p>Please click the link below to set your password. This link can be used only once:</p>
<p><a href="{{ $link }}">{{ $link }}</a></p>
<p>If you did not expect this email, ignore it.</p>
<p>Thank you!</p>