<p>Hello {{ $userName }},</p>

<p>
    Your ValiFlow account has been temporarily locked because of multiple wrong password attempts.
</p>

<p>
    Lock duration: <strong>{{ $lockoutMinutes }} minute(s)</strong>
    @if(!empty($unlockAtLabel))
        <br>You can try again after: <strong>{{ $unlockAtLabel }}</strong>
    @endif
</p>

<p>
    If this was not you, please contact your administrator immediately and reset your password using Forgot Password.
</p>

<p>Thank you,<br>Lupin Protocol Control Suite</p>
