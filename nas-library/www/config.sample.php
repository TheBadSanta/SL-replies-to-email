<?php
// Copy to config.php and fill in. Keep config.php out of anything public.
return [
  'db'           => __DIR__ . '/library.db',
  'books_root'   => '/volume1/Books',        // files outside this root are never served
  'page_password'=> '',                      // set one if the page is reachable outside your LAN

  // Send to Kindle. Add smtp_user to "Approved Personal Document E-mail List"
  // at amazon.com > Manage Your Content and Devices > Preferences > Personal Document Settings.
  'kindle_email' => 'yourname@kindle.com',
  'smtp_host'    => 'smtp.gmail.com',
  'smtp_port'    => 465,                     // 465 = SSL, 587 = STARTTLS
  'smtp_user'    => 'you@gmail.com',
  'smtp_pass'    => 'gmail-app-password',    // Gmail: myaccount.google.com/apppasswords
  'from_email'   => 'you@gmail.com',
];
