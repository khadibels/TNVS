<?php
  include('connection.php');
  session_start();

  if(isset($_POST["submit"])){
    $sql = "SELECT * FROM users WHERE username = '".$_POST["username"]."' AND password = '".$_POST["password"]."'";
    $result = mysqli_query($conn, $sql);
    $data=mysqli_fetch_array($result);
    $error = "";

  if(!empty($data)){
      $_SESSION['role']=$data['role'];
      $_SESSION['username']=$data['username'];
      header("Location: Admin/index.php");
      exit;
    }else{
      $error ="Incorrect username or password. Please try again";
    }
  }
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="style.css">
  <title>Document</title>
</head>

<body>
  <div class="header">
    <div class="icon">
      <img src="asset/image.png">
    </div>
  </div>

  <div class="greetings">
      <h1>Welcome back, Admin!</h1>
      <p>Please enter your credentials to access the dashboard.</p>
  </div>
  <?php if (!empty($error)): ?>
    <div class="error">
      <span><?php echo $error; ?></span>
    </div>
  <?php endif; ?>


  <form action="" method="post">
    <div class="login-box">
      <div class="form-control">
        <label>Username</label><br>
        <input type="text" name="username">
      </div>
      <div class="form-control">
        <label>Password</label><br>
        <input type="password" name="password">
      </div>
      <button type="submit" name="submit"  class="login-btn">LOGIN</button>
    </div>
  </form>
  <div class="footer">
    <div class="policy">
      <p>BCP Capstone |  Privacy Policy</p>
      <span>Need Help? </span>
    </div>
  </div>
  
</body>
</html>
Lance Steven
<?php
include('connection.php');
session_start();
$error = "";

if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'Super Admin') {

   	header("Location: /Administrative/login.php");
    $error = "Invalid Credentials.";
    exit;
}


?>