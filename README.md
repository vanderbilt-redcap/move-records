# Move Records Module Documentation
This module is designed to migrate records between two REDCap projects. This is done by providing a CSV file that defines how the migration should function. Below is a description on the overall configuration file and the sections it allows to be defined.

## Configuration File
The configuration file is broken up into several sections that define the migration process. The sections are:
###### Projects, Records, Events, Instances, DAGs, Fields, Behavior
In each of these sections, the first column in the CSV file defines items in the source project that is being migrated out of, and the second column defines items in the destionation project where the migration is going.

Only the 'Projects' and 'Records' sections are required. All other sections have default behavior if they are left undefined. Below each of the sections and their behavior will be explained.

### Projects
This section is required. The first column must be the REDCap project that will be the source of records to migrate. The second column must be the project which will be receiving the records. Projects are designated by their "Project ID", 

### Records
This section is required. The column structure is the same as the 'Projects' section above. Each row in this section indicates another mapping of one record ID to another.

### Events
This section is optional. It follows the same structure as the 'Records' section above. The events are designated as their "Event ID", which can be found in the REDCap project under the "Define My Events" section. All ID values should be strictly numbers.

If no events section is defined, then the system will automatically match events based on their order in the two REDCap projects. If the two projects have a differing number of events, any excess events will be ignored.

### Instances
This section is optional. It follows the same structure as the 'Records' section above. This is only valid if the project has repeating instruments or events.

If no instances section is defined, then the system will automatically keep instance numbering the same when migrating the record.

### DAGs
This section is optional. It follows the same structure as the 'Records' section above. The Data Access Groups are designated by their "Unique group name" which can be found in the DAGs section of the REDCap project. The name should be all lower case, with words separated by "_" characters.

If no "DAGs" section is defined, then the system will automatically match DAGs between projects based on their unique names being the same. If no match can be found, then the record will be migrated without a DAG.

### Fields
This section is optional. It follows the same structure as the 'Records' section above. The fields are designated by their "Variable name" which can be found in the "Online Designer" section of the REDCap project. The format for these names are all lower case with "_" characters separating words.

If no "Fields" section is defined, then the system will automatically match fields based on them having the same "Variable Name". Data will also only be migrated between fields if their field type is the same (ex: text box, radio). In the case of fields with options like checkbox or radio buttons, the available options for the fields must be the same.

### Behavior
This section is optional. The available options are "delete" or "keep".

If no "Behavior" section is defined, then the system will default to "delete". The "delete" behavior will delete the original record after it is migrated to the new project. The "keep" behavior will only move the record to its new project and leave the original record as it is.