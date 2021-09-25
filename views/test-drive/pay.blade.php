<!DOCTYPE html>
<html lang="en">

<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta http-equiv="X-UA-Compatible" content="ie=edge">
	<title>Laravel Cahier Test-drive Page 🏎️</title>

	<style>
		form {
			color: #808080
		}

		img {
			height: 200px;
			margin: 5px;
			border-radius: 2px;
		}

		input {
			border-radius: 2px;
			padding: 5px;
			border: 1px solid gray;
		}
	</style>
</head>

<body>

	<form method="post" action="{{ route('payment.show_transaction_details_for_user_confirmation') }}">

		@csrf

		<div>
			<!-- Any of the handlers listed in the Supported Payment Handlers section of this README -->
			Payment handler (Good UX tip 👍: this should be a hidden field)
			<div>
				<select name="payment_processor">
					@foreach ($paymentProviders as $paymentProvider)
					<option>{{$paymentProvider}}</option>
					@endforeach
				</select>
			</div>
		</div>
		<br>

		<div>
			Amount
			<div>
				<input type="text" name="amount" value="123" />
			</div>
		</div>
		<br>

		<div>
			Currency (ISO-4217 format. Ensure to check that the payment handler you specified above supports this currency)
			<div>
				<input type="text" name="currency" value="NGN" />
			</div>
		</div>
		<br>

		<div>
			<input type="hidden" name="user_id" value="{{$user_id}}" />
		</div>
		<br>

		<div>
			Description
			<div>
				<div>
					<img src="https://upload.wikimedia.org/wikipedia/commons/a/a1/Tesla_Model_Y_in_San_Ramon.jpg">
				</div>
				<input type="text" name="transaction_description" value="Tesla Model Y picture" />
			</div>
		</div>
		<br>

		<button type="submit">Proceed to Payment</button>
	</form>
</body>

</html>