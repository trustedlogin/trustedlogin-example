(function( $ ) {
 
    "use strict";
     
    $(document).ready( function(){

        jconfirm.pluginDefaults.useBootstrap=false;

        $('body').on('click','#trustedlogin-grant',function(e){
            $.confirm({
                title: tl_obj.intro,
                content: tl_obj.description + tl_obj.details,
                theme: 'material',
                type: 'blue',
                buttons: {
                    confirm: function () {
                        
                        var data = {
                            'action': 'tl_gen_support',
                            '_nonce': tl_obj._n,
                        };

                        console.log(data);

                        $.post(tl_obj.ajaxurl, data, function(response) {
                            console.log(response);
                            if (response.success && typeof response.data == 'object'){
                                var support_url = response.data.siteurl + '/' + response.data.endpoint + '/' + response.data.identifier;
                                
                                $.alert({
                                    icon: 'fa fa-check',
                                    theme: 'material',
                                    title: 'Support Access Granted',
                                    type: 'green',
                                    content: 'DevNote: The following URL will be used to autologin support <a href="'+support_url+'">Support URL</a> '                                });
                            } else {
                                $.alert({
                                    icon: 'fa fa-times-circle',
                                    theme: 'material',
                                    title: 'Support Access NOT Granted',
                                    type: 'red',
                                    content: 'Got this from the server: ' + JSON.stringify(response)
                                });
                            }
                            
                        }).fail(function(response) {
                            $.alert({
                                icon: 'fa fa-times-circle',
                                theme: 'material',
                                title: 'Error Connecting To Server',
                                type: 'red',
                                content: 'Got this from the server: ' + JSON.stringify(response)
                            });
                        });
                    },
                    cancel: function () {
                        $.alert({
                            icon: 'fa fa-warning',
                            theme: 'material',
                            title: 'Action Cancelled',
                            type: 'orange',
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

