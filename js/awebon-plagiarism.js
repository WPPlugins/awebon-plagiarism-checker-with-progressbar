jQuery( document ).ready(function() {

	  var plagiarism_progress_timer,
      progressbar = jQuery( "#awebon-plagarism-progressbar" ),
      progressLabel = jQuery( ".awebon-plagarism-progress-label" ),

      dialog = jQuery( "#awebon-plagarism-dialog" ).dialog({
        autoOpen: false,
        modal:true,
        width: 700,
        closeOnEscape: false,
        resizable: false,
        open: function() {
          plagiarism_progress_timer = setTimeout( checkPlagarismProgress, 3000 );
        }
      });
      dialog.parent().find('.ui-dialog-titlebar-close').hide();

          progressbar.progressbar({
		      value: false,
		      change: function() {
		        progressLabel.text( "Current Progress: " + progressbar.progressbar( "value" ) + "%" );
		      },
		      complete: function() {
		        progressLabel.text( "Completed!" );
		      }
		    });
 			progressbarValue = progressbar.find( ".ui-progressbar-value" );
			progressbarValue.css({
			          "background": '#0085ba'
			        });

			jQuery('.postbox-container #publish').click(function(e){
				dialog.dialog( "open" ); 
			});

		function checkPlagarismProgress(){
			    jQuery.ajax({
			        url: ajaxurl,
			        type: 'POST',
			        data: {
			            'action':'plagiarism_progress_check',
			        },
			        success: function  (result){
	        	      if ( result <= 99 ) {	        	      	
				        plagiarism_progress_timer = setTimeout( checkPlagarismProgress, 3000 );
				      }else{
				      	dialog.dialog( "close" ); 
				      	clearTimeout( plagiarism_progress_timer);
				      }
			            progressbar.progressbar( "value", parseInt(result));
			        },
			         error: function(errorThrown){
			            console.log(errorThrown);
			        }
			    }); 
		}

		 jQuery('#awebon-plagiarism-result-dialog').on('hidden.bs.modal', function (e) {
			    jQuery('html, body').animate({
			        scrollTop: jQuery( jQuery('#awebon_plagiarism_meta_box_id') ).offset().top
			    }, 500);
		})
		if(awebon_plagiarism.result_code != '200'){
			jQuery( "#awebon-plagiarism-result-dialog" ).find('#awebon-plagiarism-result-message').html(awebon_plagiarism.result_message);
			if(awebon_plagiarism.result_code == '300'){
				result_dialog = jQuery("#awebon-plagiarism-result-dialog");
				result_dialog.find(".dialog-header-error").css({
			          "background": '#333333'
			        });
				result_dialog.find("#awebon-plagiarism-result-message").css({
			          "color": '#333333'
			        });
				result_dialog.find(".modal-title").html("<span class='glyphicon glyphicon-check'></span> Success");
			}
			 jQuery( "#awebon-plagiarism-result-dialog" ).modal("show");
		}	   						
	
	} 
);