<?php
// Minimal SMTP client with one attachment. No dependencies.
function smtp_send_attachment(array $c, string $to, string $subject, string $file, string $fname): void {
  $port = (int)$c['smtp_port'];
  $remote = ($port === 465 ? 'ssl://' : 'tcp://') . $c['smtp_host'] . ':' . $port;
  $s = @stream_socket_client($remote, $errno, $err, 20);
  if (!$s) throw new Exception("SMTP connect failed: $err");
  stream_set_timeout($s, 60);
  $read = function () use ($s) {
    $out = '';
    while (($l = fgets($s, 1024)) !== false) { $out .= $l; if (isset($l[3]) && $l[3] === ' ') break; }
    return $out;
  };
  $cmd = function (string $line, array $ok) use ($s, $read) {
    if ($line !== '') fwrite($s, $line . "\r\n");
    $r = $read();
    if (!in_array((int)substr($r, 0, 3), $ok, true)) throw new Exception("SMTP error on '" . strtok($line, ' ') . "': " . trim($r));
    return $r;
  };
  $cmd('', [220]);
  $cmd('EHLO nas.local', [250]);
  if ($port === 587) {
    $cmd('STARTTLS', [220]);
    if (!stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new Exception('STARTTLS failed');
    $cmd('EHLO nas.local', [250]);
  }
  $cmd('AUTH LOGIN', [334]);
  $cmd(base64_encode($c['smtp_user']), [334]);
  $cmd(base64_encode($c['smtp_pass']), [235]);
  $cmd('MAIL FROM:<' . $c['from_email'] . '>', [250]);
  $cmd('RCPT TO:<' . $to . '>', [250, 251]);
  $cmd('DATA', [354]);

  $b = 'b' . bin2hex(random_bytes(12));
  $encName = '=?UTF-8?B?' . base64_encode($fname) . '?=';
  $h  = "From: <{$c['from_email']}>\r\nTo: <$to>\r\n";
  $h .= 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n";
  $h .= "Date: " . date('r') . "\r\nMIME-Version: 1.0\r\n";
  $h .= "Content-Type: multipart/mixed; boundary=\"$b\"\r\n\r\n";
  $h .= "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nSent from NAS library\r\n";
  $h .= "--$b\r\nContent-Type: application/epub+zip; name=\"$encName\"\r\n";
  $h .= "Content-Transfer-Encoding: base64\r\n";
  $h .= "Content-Disposition: attachment; filename=\"$encName\"\r\n\r\n";
  fwrite($s, $h);
  $fh = fopen($file, 'rb');
  while (!feof($fh)) { $chunk = fread($fh, 57 * 1000); fwrite($s, chunk_split(base64_encode($chunk), 76, "\r\n")); }
  fclose($fh);
  fwrite($s, "--$b--\r\n");
  $cmd('.', [250]);
  $cmd('QUIT', [221]);
  fclose($s);
}
