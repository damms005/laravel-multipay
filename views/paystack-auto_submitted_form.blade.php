<form name="pay" onload="this.submit()" action="{{route('paystack.handle_auto_submit_form')}}" method="POST">

	{{ csrf_field() }}

	<input type="hidden" name="email_prepared_for_paystack" value="<?php echo $email; ?>">
	<input type="hidden" name="user_id" value="{{$user_id}}">
	<input type="hidden" name="amount" value="<?php echo $amount_in_kobo; ?>">
	<input type="hidden" name="transaction_reference" value="<?php echo $transaction_reference; ?>">
	<button style="display: none" type="submit" name="pay_now" id="payNow" title="Pay now">Pay now</button>

	Loading...
</form>


<script>
	document.getElementById('payNow').style.visibility = "hidden";

	window.onload = function(){
		document.forms['pay'].submit();
	}
</script>