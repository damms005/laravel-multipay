<form id='form' onload="submit()" action="{{ route('payment.show_transaction_details_for_user_confirmation') }}" method="post">
	@csrf
	<input type="hidden" name="amount" value="{{ $amount }}">
	<input type="hidden" name="user_id" value="{{ $user_id }}">
	<input type="hidden" name="payment_processor" value="{{ $payment_processor }}">
	<input type="hidden" name="transaction_description" value="{{ $transaction_description }}">
</form>

<script>
	window.addEventListener('load', () => {
           document.getElementById('form').submit();
        });
</script>