<form action="{{ route('payment.show_transaction_details_for_user_confirmation') }}" method="post">
	<!-- Any of the handlers listed in the Supported Payment Handlers section of this README -->
	Payment handler. (Good UX tip 👍: this should be a hidden field)
	<input type="text" name="payment_processor" value="Paystack" />
	<br>

	<input type="text" name="amount" value="12345" />
	<br>

	<!-- ISO-4217 format. Ensure to check that the payment handler you specified above supports this currency -->
	<input type="text" name="currency" value="NGN" />
	<br>

	<!-- id of the user making the payment -->
	<input type="number" name="user_id" value="1" />
	<br>

	<input type="text" name="transaction_description" value="Payment for Tesla Model Y picture" />
</form>