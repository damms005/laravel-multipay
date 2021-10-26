<form name="pay" onload="this.submit()" action="{{ $url }}" method="POST">
	<input type="hidden" name="merchantId" value="{{ $merchantId }}">
	<input type="hidden" name="hash" value="{{ $hash }}">
	<input type="hidden" name="rrr" value="{{ $rrr }}">
	<input type="hidden" name="responseurl" value="{{$responseUrl}}">

	<button style="display: none" type="submit" name="pay_now" id="payNow" title="Pay now">Pay now</button>

	Loading...
</form>


<script>
	document.getElementById('payNow').style.visibility = "hidden";

	window.onload = function(){
		document.forms['pay'].submit();
	}
</script>