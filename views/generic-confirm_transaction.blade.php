@extends(config('laravel-multipay.extended_layout'))
@section('title', 'Payment Confirmation')

@section(config('laravel-multipay.section_name'))
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
  <div class="p-10">
    <p class="font-bold">
      {{ $instructions }}
    </p>

    <div class="mt-8">
      <span class="block font-bold text-gray-600">Description:</span> {{ $payment->transaction_description }}
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
        <button class="w-48 px-8 py-2 mr-3 text-white bg-green-900 rounded" type="submit">
          <span class="mr-2">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-6 h-6">
              <path d="M4.5 3.75a3 3 0 00-3 3v.75h21v-.75a3 3 0 00-3-3h-15z" />
              <path fill-rule="evenodd"
                    d="M22.5 9.75h-21v7.5a3 3 0 003 3h15a3 3 0 003-3v-7.5zm-18 3.75a.75.75 0 01.75-.75h6a.75.75 0 010 1.5h-6a.75.75 0 01-.75-.75zm.75 2.25a.75.75 0 000 1.5h3a.75.75 0 000-1.5h-3z"
                    clip-rule="evenodd" />
            </svg>
          </span>
          Pay Now
        </button>
      </form>
    </div>
  </div>

@endsection
