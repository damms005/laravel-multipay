<form id='form' onload="submit()" action="{{ route('payment.show_transaction_details_for_user_confirmation') }}" method="post">
	@csrf
	<input type="hidden" name="amount" value="{{ $amount }}">
	<input type="hidden" name="user_id" value="{{ $user_id }}">
	<input type="hidden" name="preferred_view" value="payment.confirm_transaction">
	<input type="hidden" name="payment_processor" value="{{ $payment_processor }}">
	<input type="hidden" name="transaction_description" value="{{ $transaction_description }}">

	{{--        START OPTIONAL FIELDS          --}}
	{{-- below is provided/available only when a model needs to be updated when transaction is successful --}}
	<input type="hidden" name="update_model_success" value="{{ $update_model_success?? '' }}" />
	<input type="hidden" name="update_model_unique_column" value="{{ $update_model_unique_column?? '' }}" />
	<input type="hidden" name="update_model_unique_column_value" value="{{ $update_model_unique_column_value?? '' }}" />
	{{--        END OPTIONAL FIELDS            --}}
</form>

<script>
	window.addEventListener('load', () => {
           document.getElementById('form').submit();
        });
</script>