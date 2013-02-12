<?php


//change settings here
$your_email = "akin.motolola@cision.com";
$your_smtp = "10.3.240.30";
$your_smtp_user = "davans";
$your_smtp_pass = "U982:uyyu:2109";
$your_website = "http://cision.com/uk";


require("phpmailer/class.phpmailer.php");


//function to check properly formed email address
function isEmailValid($email)
{
  // checks proper syntax
  if( !preg_match( "/^([a-zA-Z0-9])+([a-zA-Z0-9\._-])*@([a-zA-Z0-9_-])+([a-zA-Z0-9\._-]+)+$/", $email))
  {
	return false;
  } 
  
  return true;
  
}


//get contact form details
$name = $_POST['name'];
$email = $_POST['email'];
$pname = $_POST['pname'];
$message = $_POST['message'];


//validate email address, if it is invalid, then returns error

if (!isEmailValid($email)) {
	die('Invalid email address');
}

//start phpmailer code 

$ip = $_SERVER["REMOTE_ADDR"];
$user_agent = $_SERVER['HTTP_USER_AGENT'];



$response="Date: " . date("d F, Y h:i:s A",time()+ 16 * 3600 - 600) ."\n" . "IP Address: $ip\nProduct Name: $pname\nName: $name\nMessage:\n$message\n";
//mail("akin.motolola@mcision.com","Contact form ",$response, $headers);

$mail = new PHPmailer();
$mail->SetLanguage("en", "phpmailer/language");
$mail->From = $your_email;
$mail->FromName = $your_website;
$mail->Host = $your_smtp;
$mail->Mailer   = "smtp";
$mail->Password = $your_smtp_pass;
$mail->Username = $your_smtp_user;
$mail->Subject = "$your_website: Question about $pname by a visitor";
$mail->SMTPAuth  =  "true";

$mail->Body = $response;
$mail->AddAddress($your_email,"$your_website admin");
$mail->AddReplyTo($email,$name);


if (!$mail->Send()) {
echo "<p>There was an error in sending mail, please try again at a later time</p>";
echo "<p>".$mail->ErrorInfo."</p>";
} else {
	header("Location: http://www.cision.com/uk/product-feedback/");
}

$mail->ClearAddresses();
$mail->ClearAttachments();

?>