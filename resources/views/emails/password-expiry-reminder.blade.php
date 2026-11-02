<p>Hello {{ $userName }},</p>

<p>
    Your account password will expire in <strong>{{ $remainingLabel }}</strong>
    (on {{ $expiresAt->format('d M Y h:i A') }}).
</p>

<p>
    Please reset your password before it expires using the link below:
</p>

<p>
    <a href="{{ $resetUrl }}">{{ $resetUrl }}</a>
</p>

<p>
    After expiry you will not be able to sign in until you set a new password via Forgot Password.
</p>

<p>If you did not expect this email, please contact your administrator.</p>

<p>Thank you,<br>Lupin Protocol Control Suite</p>
