(function( $ ) {
 
    "use strict";
     
    $(document).ready( function(){

        jconfirm.pluginDefaults.useBootstrap=false;

        $('body').on('click','#trustedlogin-grant',function(e){
            $.confirm({
                title: tl_obj.intro,
                content: tl_obj.description + tl_obj.details,
                buttons: {
                    confirm: function () {
                        
                        var data = {
                            'action': 'tl_gen_support',
                            '_nonce': tl_obj._n,
                        };

                        console.log(data);

                        $.post(tl_obj.ajaxurl, data, function(response) {
                            $.alert('Got this from the server: ' + JSON.stringify(response));
                        }).fail(function(response) {
                            $.alert( "error" + JSON.stringify(response) );
                        });
                    },
                    cancel: function () {
                        $.alert({
                            title: 'Action Cancellend',
                            content: 'A support account for '+tl_obj.plugin.title+' has <em><strong>NOT</strong></em> been created.'
                        });
                    }
                    // somethingElse: {
                    //     text: 'Something else',
                    //     btnClass: 'btn-blue',
                    //     keys: ['enter', 'shift'],
                    //     action: function(){
                    //         $.alert('Something else?');
                    //     }
                    // }
                }
            });
        });

    } ); 
 
})(jQuery);

