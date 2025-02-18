Multiple helper commands are available to help you manage your library. 
For simplicity, only the command is listed here, you will need to prefix it depending on your installation:


With a standard docker compose environment: `docker-compose exec php bin/console <command>`

With ddev: `ddev exec bin/console <command>`

## app:create-admin-user
Create an admin user
## books:check  
Check all books for integrity
## books:extract-cover                       
Tries to the cover from the book and saves it in the `public/covers` folder.
## books:relocate                            
Relocate all books to their calculated folder. This is necessary only if you want Biblioteca to manage your library structure.

## books:scan                                
Will scan the `public/books` folder and add all books to the database. If a book already exists, it will be skipped.
## books:tag                                 
First you will need to setup an openAi chatgpt key in a user's properties. 
Then you can run the command to generate tag for all books in the library:
```
bin/console books:tag <user_id>
```

## cache:clear                               
Clears the cache
## doctrine:migrations:migrate               
Executes all missing database migrations
## symandy:databases:backup                  
Creates a sql backup of the database in the `backups folder`
## typesense:create                          
Will re-create the search engine index
## typesense:import                          
Will re-import all books from the database to the search engine.
