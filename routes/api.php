<?php
// Action => authentication required. Every action requires CSRF.
// Authentication actions are rate limited.
return [
    'register'=>false,'login'=>false,'logout'=>true,'forgot-password'=>false,
    'reset-password'=>false,'verify-email'=>false,'resend-verification'=>true,
];
