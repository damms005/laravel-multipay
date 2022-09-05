@extends(config('laravel-multipay.extended_layout'))
@section('title', 'Payment Summary')

@section('content')

  <style>
    @media print {

      .header,
      .footer,
      .page-titles,
      .left-sidebar {
        display: none;
      }
    }
  </style>
  <div class="">
    <p class="font-bold">
      {{ $instructions }}
    </p>

    <div class="mt-8">
      <span class="block font-bold text-gray-600">Description:</span> {{ $payment->transaction_description }}
    </div>

    <div class="mt-8">
      <span class="block font-bold text-gray-600">Payee:</span> {{ $payment->user->fullname }}
    </div>

    <div class="mt-8">
      <span class="block font-bold text-gray-600">Transaction Reference:</span> {{ $payment->transaction_reference }}
    </div>

    <div class="mt-8">
      <span class="block font-bold text-gray-600">Amount:</span> {{ $currency }} {{ number_format($payment->original_amount_displayed_to_user) }}
    </div>

    <div class="flex mt-8">
      @if (config('laravel-multipay.enable_payment_confirmation_page_print'))
        <button class="w-48 px-8 py-2 mr-3 text-white bg-blue-900 rounded" type="button" onclick="print()"><i class="mr-2 fa fa-print"></i> Print</button>
      @endif

      <form class="inline" action="{{ $post_payment_confirmation_submit }}" method="post">
        @csrf
        <input type="hidden" name="transaction_reference" value="{{ $payment->transaction_reference }}">
        <button class="w-48 px-8 py-2 mr-3 text-white bg-green-900 rounded" type="submit"><i class="mr-2 fa fa-money"></i>
          Pay Now

          @if ($payment->payment_processor_name == $unifiedPaymentName)
            <img src="{{ secure_asset('assets/images/up-pay-attitude.png') }}" alt="UPayments logo" class="h-5 m-auto">
          @endif

        </button>
      </form>

      <form class="flex-grow inline" action="{{ route('idcard.request.delete', ['idcardRequest' => $user->getLatestIdcardRequest()->id]) }}" method="get">
        @csrf
        <div class="w-full text-right">
          <button class="w-48 px-8 py-2 mr-3 text-red-900 bg-red-200 rounded " type="submit"><i class="mr-2 fa fa-window-close"></i> Cancel</button>
        </div>
      </form>
    </div>
  </div>

  @if ($payment->payment_processor_name == $unifiedPaymentName)
    <div>
      <img src="{{ secure_asset('assets/images/up-pay-attitude.png') }}" alt="UPayments logo" class="h-5 m-auto">
      PayAttitude Pay with Phone number, VbV, MasterCard Secure Code
      <div>
        "Service Provided by Unified Payment Services Limited"
      </div>
    </div>
  @endif

@endsection
