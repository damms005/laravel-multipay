@extends('master' , ['bannerImage' => 'images/hour-glass-full.jpg'])
@section('title' , 'Payment')
@section('pageName' , 'Payment')

@section('pageContainerContent')

<form name="pay" action="https://sandbox.interswitchng.com/collections/w/pay" method="post">
	<input name="hash" type="hidden" value="{{$hash}}" />
	Amount: NGN{{number_format($amount_in_naira)}}
	<input readonly name="amount" type="hidden" value="{{$amount}}" /> <br /> <br />
	<input name="txn_ref" type="hidden" value="{{$txn_ref}}" />
	<input name="product_id" type="hidden" value="{{$product_id}}" />
	<input name="pay_item_id" type="hidden" value="{{$pay_item_id}}" />
	<input name="site_redirect_url" type="hidden" value="{{$site_redirect_url}}" />
	<input name="currency" type="hidden" value="566" />
	<input name="cust_id" type="hidden" value="AD99">

	<p>
		Dear <b>{{$user->name}}</b>, please note your unique transaction reference: <code> {{$txn_ref}} </code>
	</p>
	<br>
	<p>
		Please keep this number, as you may need it in case you need to refer to this transaction.
	</p>
	<br>
	<button class="main-btn" type="submit">
		Proceed to Payment
		<img style="display: inline; border-radius: 6px; margin-left: 5px" src="{{asset('images/iswitch.png')}}" alt="" srcset="">
	</button>

</form>

@endsection