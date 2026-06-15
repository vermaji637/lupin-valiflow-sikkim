<?php

// app/Helpers/UserHelper.php
use App\Models\User;

function getUserName($id)
{
    return User::find(5)->first_name ?? 'N/A';
}
