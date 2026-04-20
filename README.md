# Send Replies from Smartlead to Client                                                                                                      
   
  An n8n workflow that listens for email replies in Smartlead campaigns and forwards them to the client via Gmail.                             
                                                                                                                                             
  ## What it does

  1. Receives a webhook from Smartlead when a lead replies                                                                                     
  2. Filters for `EMAIL_REPLY` event type
  3. Fetches lead details from Smartlead API                                                                                                   
  4. Looks up the client's name and email from a Google Sheet by Campaign ID                                                                 
  5. Fetches the full message history to extract the reply and last sent email
  6. Filters replies by AI category (Interested, Meeting Request, Information Request, Uncategorizable)                                        
  7. Sends a formatted email notification to the client with lead details and reply content
                                                                                                                                               
  ## Setup                                                                                                                                   

  1. Import `Send_replies_from_SL_to_client.json` into your n8n instance                                                                       
  2. Set your webhook path in the Webhook node
  3. Add your Smartlead API credentials                                                                                                        
  4. Connect your Google Sheets OAuth2 account and update the spreadsheet ID                                                                 
  5. Connect your Gmail OAuth2 account
  6. Update the Google Sheet with your Campaign IDs and client data                                                                            
  7. Publish the workflow
                                                                                                                                               
  ## Google Sheet structure                                                                                                                  

  The lookup sheet (`Clients data and campaigns IDs`) requires these columns:
  - `Campaign ID`
  - `FirstName`
  - `LastName`
  - `email`

  ## Spice it up

  - Add a LinkedIn sequencer branch — trigger a Heyreach sequence when a reply comes in                                                        
  - Add enrichments — run an Octave `enrich-company` or `call-prep` agent on the replying lead and include the output in the client
  notification   
