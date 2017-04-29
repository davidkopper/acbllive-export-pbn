# acbllive-export-pbn
Uses ACBLLIVE API to export generic and personalized PBN (portable bridge notation) file.

The main program file is exportpbn.php which is PHP code that creates a PBN from 
ACBLLive handrecord data.
 
 Input:  
     
     api_token (required) - password to allow access to ACBLLive APIs
		     (contact Mitch.Hodus@acbl.org to acquire an api_token)
    
     id (required) - session id (<id>-<event>-<session #>)
         ex:
           NABC171-OPEN-2
           1510209-29L3-1
           1610209-29L3-1
           1606043-04L3-1
					
     acbl_number (optional) - ACBL player number
   	     ex:
           k212501
           2212501
           K212501
      
  Output:
  	 a V2.1-compilant PBN file (personalized, if acbl_number is given)
 
  	 When acbl_number is provided (and found in the session data), 	a "personalized" PBN is created that includes
     recap data for each board played.  This PBN can then be read into programs such as
 	     Bob Richardson's Double Dummy Solver
 	     Ray Spalding's Bridge Composer
       
  Example:
  
     export_pbn.php?api_token=<your_token>\&id=NABC171-OPEN-1\&acbl_number=k212501
