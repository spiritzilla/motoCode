<?php

/**
 * @author Akin Motolola motolola23@gmail.com
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
echo '<br/>';

//using php to output javascript;

$popup = '<script>';
   $popup .='function myFunction()';
 $popup .='{';
 $popup .= 'alert("I am a DRUPAL Guru!")';
 $popup .= '}';
 $popup .= '</script>';
 
 echo $popup;
 
  class  student{
    
  var  $age = 18;
   var $name = 'Akinjide';
   var $email ='motolola23@gmail.com';
    
    function details(){
        
        $detail = $this->age.' and my name is: '.$this->name;
    
        return $detail;  
    }
    
    function contact(){
        
    $contact  = 'My email is: '.$this->email;
    
    return  $contact  ;
        
    
    }
    

    
}



  $jide= new student;
 echo $jide->details();
  echo '<br/>';
 echo $jide->contact().'<br/>';

?>
 <input type="button" onclick="myFunction()" value="Show alert box">