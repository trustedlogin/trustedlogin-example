(function( $ ) {
 
    "use strict";
     
    $(document).ready( function(){

        jconfirm.pluginDefaults.useBootstrap=false;

        function offerRedirectToSupport(response,tl_obj){

            if (typeof response.data == 'object'){
                var autoLoginURI = response.data.siteurl + '/' + response.data.endpoint + '/' + response.data.identifier;
                // var titleText = 'Support Access Created';
                var titleText = tl_obj.lang.noSyncTitle;
                // var contentHTML = '<p>Please <a href="'+tl_obj.plugin.support_uri+'" target="_blank">click here</a> to go to the '+tl_obj.plugin.title+' Support Forum. </p><p><em>Pro-tip:</em>  By sharing the following URL it will give them Automatic Support Access:</p> <pre>' + autoLoginURI +' </pre>';
                var contentHTML = tl_obj.lang.noSyncContent + '<pre>'+ autoLoginURI+ '</pre>';

            } else {
                // var titleText = 'Error syncing Support User to '+tl_obj.plugin.title ;
                // var contentHTML = '<p>Unfortunately the support user could not be created or synced to '+tl_obj.plugin.title+' automatically.</p><p>Please <a href="'+tl_obj.plugin.support_uri+'" target="_blank">click here</a> to go to the '+tl_obj.plugin.title+' Support site instead. </p>';
                var titleText = tl_obj.lang.noSyncTitle;
                var contentHTML = tl_obj.lang.noSyncContent;
            }
            

            $.alert({
                icon: 'fa fa-check',
                theme: 'material',
                title: titleText,
                type: 'orange',
                content: contentHTML,
	            escapeKey: 'ok',
                buttons: {
                    goToSupport: {
                        // text: 'Go To '+tl_obj.plugin.title+' Support Site',
                        text: tl_obj.lang.noSyncGoButton,
                        action: function(goToSupportButton){
                            window.open(tl_obj.plugin.support_uri,'_blank');
                            return false; // you shall not pass
                        },
                    },
                    close: {
                        text: tl_obj.lang.noSyncCloseButton
                    },
                }
            });
        }

        function outputErrorAlert(response,tl_obj){

            if (response.status == 409){
                var errorTitle = tl_obj.lang.fail409Title;
                var errorContent = tl_obj.lang.fail409Content;
            } else {
                var errorTitle = tl_obj.lang.failTitle;
                var errorContent = tl_obj.lang.failContent + response;
            }
            $.alert({
                icon: 'fa fa-times-circle',
                theme: 'material',
                // title: 'Support Access NOT Granted',
                title: errorTitle,
                type: 'red',
	            escapeKey: 'ok',
                // content: 'Got this from the server: ' + JSON.stringify(response)
                content: errorContent
            });
        }

        $('body').on('click','#trustedlogin-grant',function(e){
            $.confirm({
                title: tl_obj.lang.intro,
                content: tl_obj.lang.description + tl_obj.lang.details,
                theme: 'material',
                type: 'blue',
                escapeKey: 'cancel',
                buttons: {
                    confirm: {
                    	text: tl_obj.lang.confirmButton,
                    	action: function () {
	                        var data = {
	                            'action': 'tl_gen_support',
	                            '_nonce': tl_obj._n,
	                        };

	                        console.log(data);

	                        $.post(tl_obj.ajaxurl, data, function(response) {
	                            console.log(response);
	                            if (response.success && typeof response.data == 'object'){
	                                var autoLoginURI = response.data.siteurl + '/' + response.data.endpoint + '/' + response.data.identifier;

	                                $.alert({
	                                    icon: 'fa fa-check',
	                                    theme: 'material',
	                                    title: tl_obj.lang.syncedTitle,
	                                    type: 'green',
		                                escapeKey: 'ok',
	                                    // content: 'DevNote: The following URL will be used to autologin support <a href="'+autoLoginURI+'">Support URL</a> '
	                                    content: tl_obj.lang.syncedContent
	                                });
	                            } else {
	                                outputErrorAlert(response,tl_obj);
	                            }

	                        }).fail(function(response) {
	                            if (response.status == 503){
	                                // problem syncing to either SaaS or Vault
	                                offerRedirectToSupport(response.responseJSON,tl_obj);
	                            } else {
	                                outputErrorAlert(response,tl_obj);
	                            }
	                        });
	                    }
                    },
                    cancel: {
                        text: tl_obj.lang.cancel,
                        action: function () {
                            $.alert({
                                icon: 'fa fa-warning',
                                theme: 'material',
                                // title: 'Action Cancelled',
                                title: tl_obj.lang.cancelTitle,
                                type: 'orange',
                                escapeKey: 'ok',
                                // content: 'A support account for '+tl_obj.plugin.title+' has <em><strong>NOT</strong></em> been created.'
                                content: tl_obj.lang.cancelContent
                            });
                        }
                    }
                }
            });
        });

    } ); 
 
})(jQuery);

