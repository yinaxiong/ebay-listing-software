package ebaytool.apicall;

import com.mongodb.*;
import ebaytool.apicall.ApiCall;
import java.io.*;
import java.net.URL;
import java.util.*;
import java.util.concurrent.*;
import javax.net.ssl.HttpsURLConnection;
import net.sf.json.JSONObject;
import net.sf.json.JSONArray;
import net.sf.json.xml.XMLSerializer;

public class GetCategories extends ApiCall implements Callable {
	
	public GetCategories() throws Exception {
	}
	
	public String call() throws Exception {
		
		DBCursor cur = db.getCollection("US.eBayDetails.SiteDetails").find();
		Integer cnt = cur.count();
		while (cur.hasNext()) {
			DBObject row = cur.next();
			
			String  site   = row.get("Site").toString();
			Integer siteid = Integer.parseInt(row.get("SiteID").toString());
			log(site+"("+siteid+")");
			
			BasicDBObject reqdbo = new BasicDBObject();
			reqdbo.append("RequesterCredentials", new BasicDBObject("eBayAuthToken", admintoken));
			reqdbo.append("WarningLevel",   "High");
			reqdbo.append("DetailLevel",    "ReturnAll");
			reqdbo.append("CategorySiteID", siteid.toString());
			reqdbo.append("MessageID",      site);
			
			String requestxml = convertDBObject2XML(reqdbo, "GetCategories");
			writelog("GCs.req."+site+".xml", requestxml);
			
			ecs18.submit(new ApiCallTask(siteid, requestxml, "GetCategories"));

			Thread.sleep(5);
		}
		
		for (int i = 1; i <= cnt; i++) {
			String responsexml = ecs18.take().get();
			
			BasicDBObject resdbo = convertXML2DBObject(responsexml);
			
			String site = resdbo.getString("CorrelationID");
			
			log("res["+site+"]");
			writelog("GCs.res."+site+".xml", responsexml);
			
			DBCollection coll = db.getCollection(site+".Categories");
			coll.drop();
			
			coll.insert
				((List<DBObject>) ((BasicDBObject) resdbo.get("CategoryArray")).get("Category"));
		}
		
		return "";
	}
	
}
