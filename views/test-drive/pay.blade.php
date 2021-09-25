<form action="{{ route('payment.show_transaction_details_for_user_confirmation') }}" method="post">
	<div>
		<!-- Any of the handlers listed in the Supported Payment Handlers section of this README -->
		Payment handler (Good UX tip 👍: this should be a hidden field)
		<div>
			<input type="text" name="payment_processor" value="Paystack" />
		</div>
	</div>
	<br>

	<div>
		Amount
		<div>
			<input type="text" name="amount" value="12345" />
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
		ID of the user making the payment. Typically, this is an Eloquent authenticated user model
		<div>
			<input type="number" name="user_id" value="1" />
		</div>
	</div>
	<br>

	<div>
		Description
		<div>
			<input type="text" name="transaction_description" value="Payment for Tesla Model Y picture" />
		</div>
	</div>
	<br>

	<button type="submit"></button>
</form>