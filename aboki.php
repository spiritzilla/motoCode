<?php

/**
 * @author Akim Motolola akin.motolola@gmail.com
 * @copyright 2013
 */

$salute =  'I am a boy and a girl';
$greet = 'Hello People of Nigeria';
$many = $salute.' '.$greet;

echo $many; 

function mad() {
    $boy = 23;
    
    return $boy;
}
echo '<br/>'.mad();

$popup = '<script>';
   $popup .='function myFunction()';
 $popup .='{';
 $popup .= 'alert("I am an alert box!")';
 $popup .= '}';
 $popup .= '</script>';
 
 echo $popup ;

?>
